<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The counter kinds after this migration.
     *
     * Written out rather than read from application code, so this migration
     * keeps meaning the same thing whatever later code becomes.
     */
    private const ACCEPTED = ['statement', 'receipt', 'transaction', 'cash_session'];

    /**
     * The kinds it accepted before.
     */
    private const PREVIOUS = ['statement', 'receipt'];

    /**
     * Number billing transactions (TXN-) and cash shifts (CS-) per facility, like statements and receipts.
     *
     * Written per driver for the reason add_statement_only_status_to_billings_table
     * gives: PostgreSQL swaps its CHECK constraint, SQLite rebuilds the column.
     * This table has no triggers, so the rebuild loses nothing.
     */
    public function up(): void
    {
        $this->setAccepted(self::ACCEPTED);
    }

    /**
     * Narrow the column back, refusing rather than deleting the new counters.
     */
    public function down(): void
    {
        $newer = DB::table('facility_document_sequences')->whereNotIn('kind', self::PREVIOUS)->count();

        if ($newer > 0) {
            throw new RuntimeException(
                "facility_document_sequences holds {$newer} transaction or cash-shift counter(s). "
                .'Roll back the tables they number first.'
            );
        }

        $this->setAccepted(self::PREVIOUS);
    }

    /**
     * Rewrite the set of values the kind column accepts.
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

            DB::statement('ALTER TABLE facility_document_sequences DROP CONSTRAINT IF EXISTS facility_document_sequences_kind_check');
            DB::statement(
                "ALTER TABLE facility_document_sequences ADD CONSTRAINT facility_document_sequences_kind_check CHECK (kind IN ({$quoted}))"
            );

            return;
        }

        Schema::table('facility_document_sequences', function (Blueprint $table) use ($accepted): void {
            $table->enum('kind', $accepted)->change();
        });
    }
};
