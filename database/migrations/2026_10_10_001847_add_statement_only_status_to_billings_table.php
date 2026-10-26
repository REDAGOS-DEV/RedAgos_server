<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The values the status column accepts after this migration.
     *
     * Written out rather than read from the BillingStatus enum, so this
     * migration keeps meaning the same thing whatever the enum later becomes.
     */
    private const ACCEPTED = ['unpaid', 'partial', 'paid', 'void', 'subsidised', 'statement_only'];

    /**
     * The values it accepted before.
     */
    private const PREVIOUS = ['unpaid', 'partial', 'paid', 'void', 'subsidised'];

    /**
     * Let a statement say that it is billed outside RedAgos.
     *
     * A weekly (replenishment) order is billed by statement only: the project
     * owner decided on 2026-10-10 that only the patient or watcher of a Patient
     * Transfusion owes money in RedAgos. The hospital still receives a
     * statement for a weekly order, but nothing is collected against it here
     * and it does not hold units back. Unpaid would block release and leave the
     * statement in the outstanding queue for ever; Paid would claim money was
     * collected. Neither is true, so the column gains a value that is.
     *
     * Written per driver for the reason add_subsidised_status_to_billings_table
     * gives: PostgreSQL swaps its CHECK constraint, SQLite rebuilds the column.
     */
    public function up(): void
    {
        $this->setAccepted(self::ACCEPTED);
    }

    /**
     * Narrow the column back, refusing rather than rewriting statement-only rows.
     */
    public function down(): void
    {
        $statementOnly = DB::table('billings')->where('status', 'statement_only')->count();

        if ($statementOnly > 0) {
            throw new RuntimeException(
                "billings holds {$statementOnly} statement-only statement(s), which the earlier column cannot "
                .'express. Decide what each one should become before rolling this back.'
            );
        }

        $this->setAccepted(self::PREVIOUS);
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
