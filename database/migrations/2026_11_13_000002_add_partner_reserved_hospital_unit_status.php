<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The statuses after this migration, and before it.
     *
     * Written out rather than read from HospitalUnitStatus, so this migration
     * keeps meaning the same thing whatever the enum later becomes.
     */
    private const ACCEPTED = ['received', 'available', 'partner_reserved', 'tag_assigned', 'tag_crossmatched', 'pending_return', 'transfused', 'expired', 'discarded'];

    private const PREVIOUS = ['received', 'available', 'tag_assigned', 'tag_crossmatched', 'pending_return', 'transfused', 'expired', 'discarded'];

    /**
     * Name stamped on a bag's old-style mark when this is rolled back: the
     * earlier code needed a partner name to call a bag reserved.
     */
    private const ROLLBACK_PARTNER = 'Blood donor partner';

    /**
     * A bag reserved for the blood donor partner program is a status of its
     * own, not a mark on an available bag (project owner, 2026-11-13): it
     * leaves the issuable stock and cannot be tagged to a patient. No partner
     * name is asked for any more.
     *
     * An available bag that carried the old mark becomes Partner Reserved. A
     * bag tagged while it carried the mark keeps its tag and loses the mark,
     * since a reserved bag can no longer be tagged. partner_name stays as a
     * column, unwritten, in case a partner is named again later;
     * partner_reserved_at and partner_reserved_by still say when and by whom.
     */
    public function up(): void
    {
        $this->setAccepted(self::ACCEPTED);

        DB::table('hospital_units')
            ->where('status', 'available')
            ->whereNotNull('partner_name')
            ->update(['status' => 'partner_reserved', 'partner_name' => null]);

        DB::table('hospital_units')
            ->where('status', '!=', 'partner_reserved')
            ->where(fn ($q) => $q->whereNotNull('partner_name')->orWhereNotNull('partner_reserved_at'))
            ->update(['partner_name' => null, 'partner_reserved_at' => null, 'partner_reserved_by' => null]);
    }

    /**
     * Back to the mark: a reserved bag becomes available again, marked.
     */
    public function down(): void
    {
        DB::table('hospital_units')
            ->where('status', 'partner_reserved')
            ->update(['status' => 'available', 'partner_name' => self::ROLLBACK_PARTNER]);

        $this->setAccepted(self::PREVIOUS);
    }

    /**
     * Rewrite the set of values the status column accepts.
     *
     * PostgreSQL swaps the CHECK constraint; SQLite rebuilds the column, as in
     * add_stock_in_to_hospital_units_table.
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

            DB::statement('ALTER TABLE hospital_units DROP CONSTRAINT IF EXISTS hospital_units_status_check');
            DB::statement("ALTER TABLE hospital_units ADD CONSTRAINT hospital_units_status_check CHECK (status IN ({$quoted}))");

            return;
        }

        // The rebuild drops and recreates the table, which unit_tags points at.
        Schema::withoutForeignKeyConstraints(function () use ($accepted): void {
            Schema::table('hospital_units', function (Blueprint $table) use ($accepted): void {
                $table->enum('status', $accepted)->default('available')->change();
            });
        });
    }
};
