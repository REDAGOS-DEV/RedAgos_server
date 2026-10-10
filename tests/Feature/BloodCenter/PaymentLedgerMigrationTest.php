<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\PaymentSource;
use App\Models\AuditLog;
use App\Models\Billing;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * The payments table as a delete-protected, correction-controlled ledger.
 *
 * Covers the three Phase 1 migrations: the source and recorder columns, the
 * recorder backfill from the audit trail, and the triggers that hold the
 * ledger rules in the database itself. Raw query-builder writes are used on
 * purpose, because the triggers exist for the writes that bypass the model.
 *
 * Every write expected to fail runs in its own nested transaction, a
 * savepoint, so that on PostgreSQL the refusal does not abort the test's
 * transaction. Rollback runs inside the test's transaction, which SQLite
 * allows for DDL, so the shared in-memory schema is restored when each test
 * ends; on PostgreSQL the triggers and rollback are left to the CI run against
 * a real database.
 */
class PaymentLedgerMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ADD_COLUMNS = '2026_10_09_231033_add_source_and_recorded_by_to_payments_table.php';

    private const BACKFILL = '2026_10_09_231036_backfill_recorded_by_on_payments.php';

    private const TRIGGERS = '2026_10_09_231038_add_ledger_triggers_to_payments_table.php';

    private function migration(string $file): Migration
    {
        return require database_path("migrations/{$file}");
    }

    /**
     * Assert the database itself refuses a write.
     */
    private function assertRefusedByDatabase(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('The database should have refused that write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function gatewayPayment(): Payment
    {
        return Payment::factory()->gcash('XND-PAY-1')->create(['source' => PaymentSource::Gateway]);
    }

    // --- Columns and backfill --------------------------------------------------------

    public function test_a_payment_written_without_a_source_is_manual(): void
    {
        $billing = Billing::factory()->create();

        $id = DB::table('payments')->insertGetId([
            'billing_id' => $billing->id,
            'amount_paid' => '100.00',
            'payment_method' => 'cash',
            'status' => 'completed',
            'payment_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('manual', DB::table('payments')->where('id', $id)->value('source'));
    }

    public function test_the_recorder_is_backfilled_from_the_audit_trail(): void
    {
        $clerk = User::factory()->create();
        $earlierClerk = User::factory()->create();

        $logged = Payment::factory()->create();
        $unlogged = Payment::factory()->create();
        $alreadyNamed = Payment::factory()->create(['recorded_by' => $earlierClerk->id]);

        foreach ([$logged, $alreadyNamed] as $payment) {
            AuditLog::create([
                'actor_id' => $clerk->id,
                'action' => 'billing.payment_recorded',
                'auditable_type' => Billing::class,
                'auditable_id' => $payment->billing_id,
                'context' => ['payment_id' => $payment->id],
            ]);
        }

        // Another action naming the unlogged payment is not who recorded it.
        AuditLog::create([
            'actor_id' => $clerk->id,
            'action' => 'billing.payment_corrected',
            'context' => ['payment_id' => $unlogged->id],
        ]);

        $this->migration(self::BACKFILL)->up();

        $this->assertSame($clerk->id, $logged->refresh()->recorded_by);
        $this->assertNull($unlogged->refresh()->recorded_by, 'No entry means unknown, not anybody.');
        $this->assertSame($earlierClerk->id, $alreadyNamed->refresh()->recorded_by, 'A recorder already stored is kept.');
    }

    // --- The ledger rules, in the database ----------------------------------------------

    public function test_the_database_refuses_to_delete_a_payment(): void
    {
        $payment = Payment::factory()->create();

        $this->assertRefusedByDatabase(fn () => DB::table('payments')->where('id', $payment->id)->delete());

        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_the_database_refuses_to_change_a_gateway_payment(): void
    {
        $payment = $this->gatewayPayment();

        $this->assertRefusedByDatabase(fn () => DB::table('payments')
            ->where('id', $payment->id)
            ->update(['amount_paid' => '1.00']));

        $this->assertSame('1500.00', $payment->refresh()->amount_paid);
    }

    public function test_the_database_refuses_to_turn_a_manual_payment_into_a_gateway_one(): void
    {
        $payment = Payment::factory()->create();

        // Relabel, then edit: the bypass a rule on the old source alone would allow.
        $this->assertRefusedByDatabase(fn () => DB::table('payments')
            ->where('id', $payment->id)
            ->update(['source' => 'gateway']));

        $this->assertSame(PaymentSource::Manual, $payment->refresh()->source);
    }

    public function test_the_database_refuses_to_turn_a_gateway_payment_into_a_manual_one(): void
    {
        $payment = $this->gatewayPayment();

        $this->assertRefusedByDatabase(fn () => DB::table('payments')
            ->where('id', $payment->id)
            ->update(['source' => 'manual']));

        $this->assertSame(PaymentSource::Gateway, $payment->refresh()->source);
    }

    public function test_a_manual_payment_can_still_be_corrected(): void
    {
        $payment = Payment::factory()->create();

        // The approved correction workflow updates a manual payment in place.
        $updated = DB::table('payments')->where('id', $payment->id)->update(['amount_paid' => '1200.00']);

        $this->assertSame(1, $updated);
        $this->assertSame('1200.00', $payment->refresh()->amount_paid);
    }

    // --- The same rules, earlier, in the model ----------------------------------------

    public function test_the_model_refuses_to_delete_a_payment(): void
    {
        $payment = Payment::factory()->create();

        $this->expectException(LogicException::class);

        $payment->delete();
    }

    public function test_the_model_refuses_to_change_a_payments_source(): void
    {
        $payment = Payment::factory()->create();

        $this->expectException(LogicException::class);

        $payment->update(['source' => PaymentSource::Gateway]);
    }

    public function test_the_model_refuses_to_change_a_gateway_payment(): void
    {
        $payment = $this->gatewayPayment();

        $this->expectException(LogicException::class);

        $payment->update(['amount_paid' => 1]);
    }

    // --- The migrations themselves --------------------------------------------------

    public function test_the_migrations_depend_on_no_application_code(): void
    {
        foreach ([self::ADD_COLUMNS, self::BACKFILL, self::TRIGGERS] as $file) {
            $this->assertStringNotContainsString(
                'App\\',
                (string) file_get_contents(database_path("migrations/{$file}")),
                "{$file} must not depend on application classes that may later change."
            );
        }
    }

    public function test_rolling_back_is_refused_while_a_gateway_payment_exists_and_changes_nothing(): void
    {
        $this->gatewayPayment();

        try {
            $this->migration(self::ADD_COLUMNS)->down();
            $this->fail('A gateway payment must stop the rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('gateway', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('payments', 'source'));
        $this->assertTrue(Schema::hasColumn('payments', 'recorded_by'));
    }

    public function test_rolling_back_without_gateway_payments_restores_the_earlier_table(): void
    {
        Payment::factory()->create();

        $this->migration(self::TRIGGERS)->down();
        $this->migration(self::BACKFILL)->down();
        $this->migration(self::ADD_COLUMNS)->down();

        $this->assertFalse(Schema::hasColumn('payments', 'source'));
        $this->assertFalse(Schema::hasColumn('payments', 'recorded_by'));
        $this->assertSame(1, DB::table('payments')->count());
    }
}
