<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The hospital unit statuses after this migration, and before it.
     *
     * Written out rather than read from HospitalUnitStatus, so this migration
     * keeps meaning the same thing whatever the enum later becomes.
     */
    private const ACCEPTED = ['received', 'available', 'partner_reserved', 'tag_assigned', 'tag_crossmatched', 'pending_return', 'transfused', 'expired', 'discarded', 'transferred'];

    private const PREVIOUS = ['received', 'available', 'partner_reserved', 'tag_assigned', 'tag_crossmatched', 'pending_return', 'transfused', 'expired', 'discarded'];

    /**
     * The allocation statuses that lay claim to a bag, as the claimed-unit
     * index has always read them.
     */
    private const CLAIMING = ['allocated', 'released'];

    /**
     * A hospital blood bank may supply another hospital's Blood Request from
     * its own near-expiry stock (project owner, 2026-11-28).
     *
     * The bag goes as a share of that request, released at once, and the
     * other hospital receives it as it would a centre's delivery. So one bag
     * can now have been in two hospitals' custody, and carried by two
     * dispatched holds:
     *
     *  - hospital_units: the giving hospital's row ends as `transferred`, with
     *    when, by whom and the hold that carried it on. unit_id is unique only
     *    among rows still in a hospital's custody.
     *  - request_allocations: the hold that brought the bag to the giving
     *    hospital is stamped passed_on_at. It still counts as released for its
     *    own request, which did receive the bag, but no longer claims the bag,
     *    so the onward hold can.
     */
    public function up(): void
    {
        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->timestamp('transferred_at')->nullable();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('transfer_allocation_id')->nullable()->constrained('request_allocations')->cascadeOnUpdate()->restrictOnDelete();
        });

        $this->setAccepted(self::ACCEPTED);

        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->dropUnique('hospital_units_unit_id_unique');
            $table->index('unit_id', 'hospital_units_unit_id_index');
        });

        $this->createHeldUnitIndex();

        // Taken down and put back around the change: a table rebuild on
        // sqlite reinstates it without its WHERE clause.
        $this->dropClaimedUnitIndex();

        Schema::table('request_allocations', function (Blueprint $table): void {
            $table->timestamp('passed_on_at')->nullable()->after('received_by');
        });

        $this->createClaimedUnitIndex(true);
    }

    public function down(): void
    {
        if (DB::table('hospital_units')->where('status', 'transferred')->exists()) {
            throw new RuntimeException('Bags have been supplied from one hospital to another; their custody rows cannot be made unique again.');
        }

        $this->dropClaimedUnitIndex();

        Schema::table('request_allocations', function (Blueprint $table): void {
            $table->dropColumn('passed_on_at');
        });

        $this->createClaimedUnitIndex(false);

        DB::statement('DROP INDEX IF EXISTS hospital_units_held_unit_unique');

        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->dropIndex('hospital_units_unit_id_index');
            $table->unique('unit_id');
        });

        Schema::table('hospital_units', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transfer_allocation_id');
            $table->dropConstrainedForeignId('transferred_by');
            $table->dropColumn('transferred_at');
        });

        $this->setAccepted(self::PREVIOUS);
    }

    /**
     * One custody row per bag among the hospitals still holding it.
     */
    private function createHeldUnitIndex(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'pgsql', 'sqlite' => DB::statement(
                "CREATE UNIQUE INDEX hospital_units_held_unit_unique ON hospital_units (unit_id) WHERE status <> 'transferred'"
            ),
            default => null,
        };
    }

    /**
     * One live claim per bag: a hold passed on to another hospital claims it no longer.
     */
    private function createClaimedUnitIndex(bool $skipPassedOn): void
    {
        $connection = Schema::getConnection();
        $pdo = $connection->getPdo();

        $claiming = implode(', ', array_map(fn (string $value): string => $pdo->quote($value), self::CLAIMING));
        $where = "status IN ({$claiming})".($skipPassedOn ? ' AND passed_on_at IS NULL' : '');

        match ($connection->getDriverName()) {
            'pgsql', 'sqlite' => DB::statement(
                "CREATE UNIQUE INDEX request_allocations_claimed_unit_unique ON request_allocations (unit_id) WHERE {$where}"
            ),
            default => null,
        };
    }

    private function dropClaimedUnitIndex(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'pgsql', 'sqlite' => DB::statement('DROP INDEX IF EXISTS request_allocations_claimed_unit_unique'),
            default => null,
        };
    }

    /**
     * Rewrite the set of values hospital_units.status accepts.
     *
     * PostgreSQL swaps the CHECK constraint; SQLite rebuilds the column, as in
     * add_partner_reserved_hospital_unit_status.
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
