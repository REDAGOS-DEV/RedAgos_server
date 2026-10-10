<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The billing journal: one numbered, categorised row for every money event.
     *
     * The statement (billings) answers "what is owed now"; payments hold the
     * money received. Neither says, in one place, everything that happened to
     * a bill and when — a charge growing with each reservation, a payment, a
     * subsidy waiving it, a correction, a void, a weekly bill settled by the
     * hospital. This table does: each event is a row with its own TXN-
     * number, what it was for (category), what it was (type), where it came
     * in (channel), its signed amount — positive raises what is owed,
     * negative settles it — and the bill's balance after it.
     *
     * Append-only, held by triggers on PostgreSQL and SQLite as the payments
     * ledger and revisions are: no row is ever changed or deleted. A mistake is
     * answered by a further row, never by an edit.
     *
     * Existing bills are carried in: an opening balance (the charge before any
     * subsidy), one row per payment collected, and the subsidy if one was
     * applied, so every bill's rows add up to its balance today.
     *
     * Values are literals rather than application enums, so this migration
     * never depends on later application code.
     */
    public function up(): void
    {
        Schema::create('billing_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_number', 40);
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('billing_id')->constrained('billings')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('request_id')->constrained('blood_requests')->cascadeOnUpdate()->restrictOnDelete();
            $table->enum('category', ['patient_transfusion', 'weekly_replenishment']);
            $table->enum('type', [
                'charge',
                'charge_adjustment',
                'payment',
                'payment_correction',
                'payment_void',
                'subsidy',
                'external_settlement',
                'opening_balance',
            ]);
            $table->enum('channel', ['counter', 'gateway', 'outside', 'system']);
            $table->enum('payment_method', ['cash', 'gcash'])->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->foreignId('payment_id')->nullable()->constrained('payments')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('payment_receipt_id')->nullable()->constrained('payment_receipts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('billing_revision_id')->nullable()->constrained('billing_revisions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained('payment_attempts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained('cash_sessions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('correction_request_id')->nullable()->constrained('correction_requests')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('reverses_transaction_id')->nullable()->constrained('billing_transactions')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['facility_id', 'transaction_number']);
            $table->index(['facility_id', 'occurred_at']);
            $table->index(['billing_id', 'id']);
            $table->index('cash_session_id');
            $table->index(['category', 'type']);
        });

        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => $this->createTriggersOnPostgres(),
            'sqlite' => $this->createTriggersOnSqlite(),
            default => null,
        };

        $this->carryInExistingBills();
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS billing_transactions_append_only ON billing_transactions');
            DB::unprepared('DROP FUNCTION IF EXISTS billing_transactions_refuse_change()');
        }

        Schema::dropIfExists('billing_transactions');

        DB::table('facility_document_sequences')->where('kind', 'transaction')->delete();
    }

    private function createTriggersOnPostgres(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION billing_transactions_refuse_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'billing_transactions: a billing transaction cannot be changed or deleted'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER billing_transactions_append_only
                BEFORE UPDATE OR DELETE ON billing_transactions
                FOR EACH ROW EXECUTE FUNCTION billing_transactions_refuse_change()
            SQL);
    }

    private function createTriggersOnSqlite(): void
    {
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'billing_transactions_no_'.strtolower($operation);

            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name}
                BEFORE {$operation} ON billing_transactions
                BEGIN
                    SELECT RAISE(ABORT, 'billing_transactions: a billing transaction cannot be changed or deleted');
                END
                SQL);
        }
    }

    /**
     * Write each existing bill's history so its rows add up to its balance today.
     */
    private function carryInExistingBills(): void
    {
        $bills = DB::table('billings')
            ->join('blood_requests', 'blood_requests.id', '=', 'billings.request_id')
            ->select('billings.*', 'blood_requests.request_purpose', 'blood_requests.target_facility_id')
            ->orderBy('billings.id')
            ->get();

        foreach ($bills as $bill) {
            $base = [
                'facility_id' => (int) $bill->target_facility_id,
                'billing_id' => $bill->id,
                'request_id' => $bill->request_id,
                'category' => $bill->request_purpose === 'replenishment' ? 'weekly_replenishment' : 'patient_transfusion',
            ];

            $subsidy = $bill->status === 'subsidised' ? $this->subsidyOf($bill->id) : null;
            $waived = $subsidy['amount'] ?? 0;

            $entries = [[
                'type' => 'opening_balance',
                'channel' => 'system',
                // The charge before any subsidy zeroed it.
                'amount' => $this->centavos($bill->total_amount) + $waived,
                'occurred_at' => $bill->billing_date ?? $bill->created_at,
                'note' => 'Carried in when the billing journal began.',
            ]];

            $payments = DB::table('payments')
                ->where('billing_id', $bill->id)
                ->where('status', 'completed')
                ->orderBy('payment_date')
                ->orderBy('id')
                ->get();

            foreach ($payments as $payment) {
                $entries[] = [
                    'type' => 'payment',
                    'channel' => $payment->source === 'gateway' ? 'gateway' : 'counter',
                    'payment_method' => $payment->payment_method,
                    'amount' => -$this->centavos($payment->amount_paid),
                    'payment_id' => $payment->id,
                    'payment_receipt_id' => DB::table('payment_receipts')
                        ->where('payment_id', $payment->id)
                        ->whereNull('voided_at')
                        ->value('id'),
                    'billing_revision_id' => $payment->billing_revision_id,
                    'payment_attempt_id' => $payment->payment_attempt_id,
                    'reference' => $payment->reference_number,
                    'recorded_by' => $payment->recorded_by,
                    'occurred_at' => $payment->payment_date ?? $payment->created_at,
                ];
            }

            if ($subsidy !== null) {
                $entries[] = [
                    'type' => 'subsidy',
                    'channel' => 'system',
                    'amount' => -$waived,
                    'recorded_by' => $subsidy['actor_id'],
                    'occurred_at' => $subsidy['at'] ?? $bill->updated_at,
                    'note' => 'Covered by the government subsidy.',
                ];
            }

            $this->insertInOrder($base, $entries);
        }
    }

    /**
     * Number and insert one bill's entries, oldest first, with the running balance.
     *
     * @param  array<string, mixed>  $base
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function insertInOrder(array $base, array $entries): void
    {
        // Stable: the opening balance stays first when timestamps tie.
        $keyed = array_map(fn (array $entry, int $index): array => [$entry, $index], $entries, array_keys($entries));
        usort($keyed, fn (array $a, array $b): int => [(string) $a[0]['occurred_at'], $a[1]] <=> [(string) $b[0]['occurred_at'], $b[1]]);

        $balance = 0;

        foreach ($keyed as [$entry]) {
            $balance += $entry['amount'];

            DB::table('billing_transactions')->insert([
                ...$base,
                'transaction_number' => sprintf('TXN-%d-%06d', $base['facility_id'], $this->nextNumber($base['facility_id'])),
                'type' => $entry['type'],
                'channel' => $entry['channel'],
                'payment_method' => $entry['payment_method'] ?? null,
                'amount' => $this->decimal($entry['amount']),
                'balance_after' => $this->decimal($balance),
                'payment_id' => $entry['payment_id'] ?? null,
                'payment_receipt_id' => $entry['payment_receipt_id'] ?? null,
                'billing_revision_id' => $entry['billing_revision_id'] ?? null,
                'payment_attempt_id' => $entry['payment_attempt_id'] ?? null,
                'reference' => $entry['reference'] ?? null,
                'note' => $entry['note'] ?? null,
                'recorded_by' => $entry['recorded_by'] ?? null,
                'occurred_at' => $entry['occurred_at'] ?? now(),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * The amount a subsidy waived, as its audit row recorded it, with who applied it and when.
     *
     * @return array{amount: int, actor_id: int|null, at: string|null}
     */
    private function subsidyOf(int $billingId): array
    {
        $audit = DB::table('audit_logs')
            ->where('action', 'billing.subsidised')
            ->where('auditable_id', $billingId)
            ->where('auditable_type', 'App\Models\Billing')
            ->orderByDesc('id')
            ->first();

        $context = $audit?->context ? json_decode((string) $audit->context, true) : [];

        return [
            'amount' => $this->centavos($context['amount_waived'] ?? 0),
            'actor_id' => $audit?->actor_id,
            'at' => $audit?->created_at,
        ];
    }

    private function nextNumber(int $facilityId): int
    {
        DB::table('facility_document_sequences')->insertOrIgnore([
            'facility_id' => $facilityId,
            'kind' => 'transaction',
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counter = DB::table('facility_document_sequences')
            ->where('facility_id', $facilityId)
            ->where('kind', 'transaction')
            ->first();

        DB::table('facility_document_sequences')
            ->where('id', $counter->id)
            ->update(['next_value' => $counter->next_value + 1, 'updated_at' => now()]);

        return (int) $counter->next_value;
    }

    private function centavos(int|float|string|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    private function decimal(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
};
