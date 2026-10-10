<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The values the status column accepts after this migration, and before it.
     *
     * Written out rather than read from the BillingStatus enum, so this
     * migration keeps meaning the same thing whatever the enum later becomes.
     */
    private const ACCEPTED = ['unpaid', 'partial', 'paid', 'void', 'subsidised', 'statement_only', 'settled_outside'];

    private const PREVIOUS = ['unpaid', 'partial', 'paid', 'void', 'subsidised', 'statement_only'];

    /**
     * Record when a hospital settles a weekly bill outside RedAgos.
     *
     * A weekly (replenishment) order is billed to the hospital by statement
     * only, and the money never moves through RedAgos (owner, 2026-10-10).
     * Until now nothing said whether the hospital had paid it, so a weekly
     * statement stayed open for ever. Billing staff now record the hospital's
     * reference and the date it settled (owner, 2026-10-11); the statement
     * moves to `settled_outside`, and its figure is frozen.
     *
     * Written per driver for the reason add_statement_only_status_to_billings_table
     * gives: PostgreSQL swaps its CHECK constraint, SQLite rebuilds the column.
     */
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table): void {
            $table->date('settled_at')->nullable();
            $table->string('settlement_reference', 100)->nullable();
            $table->string('settlement_note', 500)->nullable();
        });

        Schema::table('billings', function (Blueprint $table): void {
            $table->foreignId('settled_by')->nullable()->after('settled_at')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
        });

        $this->setAccepted(self::ACCEPTED);
    }

    /**
     * Narrow the column back, refusing rather than rewriting settled statements.
     */
    public function down(): void
    {
        $settled = DB::table('billings')->where('status', 'settled_outside')->count();

        if ($settled > 0) {
            throw new RuntimeException(
                "billings holds {$settled} weekly statement(s) settled by the hospital, which the earlier column cannot "
                .'express. Decide what each one should become before rolling this back.'
            );
        }

        $this->setAccepted(self::PREVIOUS);

        Schema::table('billings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('settled_by');
        });

        Schema::table('billings', function (Blueprint $table): void {
            $table->dropColumn(['settled_at', 'settlement_reference', 'settlement_note']);
        });
    }

    /**
     * Rewrite the set of values the status column accepts.
     *
     * @param  array<int, string>  $accepted
     */
    private function setAccepted(array $accepted): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $quoted = implode(', ', array_map(
                fn (string $value): string => $connection->getPdo()->quote($value),
                $accepted
            ));

            DB::statement('ALTER TABLE billings DROP CONSTRAINT IF EXISTS billings_status_check');
            DB::statement(
                "ALTER TABLE billings ADD CONSTRAINT billings_status_check CHECK (status IN ({$quoted}))"
            );

            return;
        }

        Schema::table('billings', function (Blueprint $table) use ($accepted): void {
            $table->enum('status', $accepted)->default('unpaid')->change();
        });
    }
};
