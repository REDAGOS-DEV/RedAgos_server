<?php

use App\Enums\AllocationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep who physically took a dispatched unit on the allocation itself.
     *
     * Until now release() wrote the name only into the request's history
     * event, so the allocation row could not say, and a correction to a
     * dispatch record had nothing to correct. It stays on the history event
     * too, which remains the record of what was said at the counter.
     *
     * The claimed-unit partial unique index is taken down and put back around
     * the change, for the reason add_request_item_id_to_request_allocations
     * gives: a table rebuild on sqlite reinstates it without its WHERE clause.
     */
    public function up(): void
    {
        $this->dropClaimedUnitIndex();

        Schema::table('request_allocations', function (Blueprint $table): void {
            $table->string('handed_to', 150)->nullable()->after('released_by');
        });

        $this->createClaimedUnitIndex();
    }

    public function down(): void
    {
        $this->dropClaimedUnitIndex();

        Schema::table('request_allocations', function (Blueprint $table): void {
            $table->dropColumn('handed_to');
        });

        $this->createClaimedUnitIndex();
    }

    /**
     * Recreate the claimed-only unique index on drivers that support one.
     *
     * A copy of the logic in add_request_item_id_to_request_allocations rather
     * than a call into it: a migration must keep working against the schema as
     * it was at its own point in history.
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

    private function dropClaimedUnitIndex(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'pgsql', 'sqlite' => DB::statement('DROP INDEX IF EXISTS request_allocations_claimed_unit_unique'),
            default => null,
        };
    }
};
