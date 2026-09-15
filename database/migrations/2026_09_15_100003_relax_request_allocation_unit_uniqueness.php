<?php

use App\Enums\AllocationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Narrow the unit uniqueness rule from "ever" to "while claimed".
     *
     * The original UNIQUE(unit_id) is what stops the same bag being promised to
     * two requests, and that part must survive. What it also does, though, is
     * burn the unit permanently: once any allocation row exists for a unit, no
     * second row can ever be inserted, so a hold that is given up can never be
     * re-allocated to anybody. docs/IMPLEMENTATION_DECISIONS.md records this as
     * an open conflict with the release-and-reallocate workflow. This resolves
     * it.
     *
     * The replacement is a partial unique index covering only the statuses that
     * still lay claim to the unit — `allocated` and `released`. A `cancelled`
     * row stops participating, so the unit becomes allocatable again while two
     * live claims remain impossible.
     *
     * Driver support is the catch. PostgreSQL (production) and SQLite (tests)
     * both index partially; MySQL and MariaDB do not. On those, the guarantee
     * falls back to the row lock and status re-assertion in
     * RequestAllocationService, which every driver relies on as the first line
     * anyway — the index is the backstop for when application code is wrong,
     * not the primary mechanism.
     */
    public function up(): void
    {
        // Added before the unique is dropped, not after: on MySQL the foreign
        // key on unit_id needs a supporting index, and the unique is currently
        // the only one. Dropping it first would leave the FK unsupported.
        Schema::table('request_allocations', function (Blueprint $table): void {
            $table->index('unit_id', 'request_allocations_unit_id_index');
        });

        Schema::table('request_allocations', function (Blueprint $table): void {
            $table->dropUnique(['unit_id']);
        });

        $this->createPartialUniqueIndex();
    }

    /**
     * Restore the global uniqueness rule.
     *
     * This legitimately fails if any unit has been re-allocated since, because
     * the old constraint cannot describe that data. A loud failure is the right
     * outcome there — silently dropping one of two real allocations to make a
     * rollback succeed would lose a record of where blood went.
     */
    public function down(): void
    {
        $this->dropPartialUniqueIndex();

        Schema::table('request_allocations', function (Blueprint $table): void {
            $table->unique('unit_id');
            $table->dropIndex('request_allocations_unit_id_index');
        });
    }

    /**
     * Create the claimed-only unique index on drivers that support one.
     *
     * Written as raw SQL because the schema builder has no partial-index API.
     * The status values are quoted through the connection's own PDO rather than
     * concatenated: they originate in a PHP enum and are not attacker
     * controlled, but a DDL statement cannot take bindings and the quoting
     * still has to be real.
     */
    private function createPartialUniqueIndex(): void
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
     * Drop the claimed-only unique index where one was created.
     */
    private function dropPartialUniqueIndex(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'pgsql', 'sqlite' => DB::statement('DROP INDEX IF EXISTS request_allocations_claimed_unit_unique'),
            default => null,
        };
    }
};
