<?php

use App\Enums\AllocationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record which line of a request each held unit answers.
     *
     * With one component per request, request_id alone said what a held unit
     * was for. Now that a request can ask for packed cells and platelets on one
     * form, it no longer does: without this column there is no way to say how
     * much of the platelet line is covered, and billing cannot price a hold
     * whose component it cannot name.
     *
     * Nullable only so the backfill below cannot fail on an allocation whose
     * request somehow has no line. RequestAllocationService always sets it.
     */
    public function up(): void
    {
        // SQLite cannot add a foreign key in place, so the schema builder
        // rebuilds the table — and the rebuild reinstates the claimed-unit
        // index without its WHERE clause, quietly turning a partial unique
        // into a global one. That is exactly the constraint
        // relax_request_allocation_unit_uniqueness removed, and leaving it
        // would make a released unit unallocatable for a second time. Taking
        // the index down first and putting it back after is the only way to
        // be sure of what ends up on the table.
        $this->dropClaimedUnitIndex();

        Schema::table('request_allocations', function (Blueprint $table): void {
            $table->foreignId('request_item_id')->nullable()->after('request_id')
                ->constrained('blood_request_items')->cascadeOnUpdate()->cascadeOnDelete();
        });

        $this->createClaimedUnitIndex();

        $this->pointExistingHoldsAtTheirLine();
    }

    public function down(): void
    {
        $this->dropClaimedUnitIndex();

        Schema::table('request_allocations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('request_item_id');
        });

        $this->createClaimedUnitIndex();
    }

    /**
     * Recreate the claimed-only unique index on drivers that support one.
     *
     * Deliberately a copy of the logic in
     * relax_request_allocation_unit_uniqueness rather than a call into it: a
     * migration must keep working against the schema as it was at its own
     * point in history, and reaching into another migration's private method
     * would couple the two for ever.
     */
    private function createClaimedUnitIndex(): void
    {
        $connection = Schema::getConnection();
        $pdo = $connection->getPdo();

        $claiming = implode(', ', array_map(
            fn (string $value): string => $pdo->quote($value),
            AllocationStatus::claimingValues()
        ));

        match ($connection->getDriverName()) {
            'pgsql', 'sqlite' => DB::statement(
                'CREATE UNIQUE INDEX request_allocations_claimed_unit_unique '
                ."ON request_allocations (unit_id) WHERE status IN ({$claiming})"
            ),
            default => null,
        };
    }

    /**
     * Drop the claimed-only unique index where one exists.
     */
    private function dropClaimedUnitIndex(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'pgsql', 'sqlite' => DB::statement('DROP INDEX IF EXISTS request_allocations_claimed_unit_unique'),
            default => null,
        };
    }

    /**
     * Attach every pre-existing hold to the single line its request was migrated into.
     */
    private function pointExistingHoldsAtTheirLine(): void
    {
        DB::table('blood_request_items')
            ->select(['id', 'request_id'])
            ->orderBy('id')
            ->chunk(500, function ($items): void {
                foreach ($items as $item) {
                    DB::table('request_allocations')
                        ->where('request_id', $item->request_id)
                        ->whereNull('request_item_id')
                        ->update(['request_item_id' => $item->id]);
                }
            });
    }
};
