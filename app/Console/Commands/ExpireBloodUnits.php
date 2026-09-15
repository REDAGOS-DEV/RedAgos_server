<?php

namespace App\Console\Commands;

use App\Enums\AllocationStatus;
use App\Enums\BloodUnitStatus;
use App\Models\RequestAllocation;
use App\Repository\InventoryRepository;
use App\Service\AuditLogger;
use App\Support\OperationalDay;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Move past-expiry available units to expired.
 *
 * Idempotent: a second run matches nothing, because the first left no available
 * rows behind it. This is why the listing never re-labels a unit's status on
 * the fly — the stored status is the single truth, and this is what moves it.
 *
 * Touches `available` only. A reserved unit that passes its expiry has to be
 * released from its allocation first, and releasing is the allocation module's
 * business.
 */
class ExpireBloodUnits extends Command
{
    /**
     * How many candidates each locked transaction handles.
     */
    private const CHUNK = 500;

    protected $signature = 'inventory:expire-units';

    protected $description = 'Expire blood units whose expiry date has passed';

    public function __construct(
        private readonly InventoryRepository $inventoryRepository,
        private readonly AuditLogger $auditLogger
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = OperationalDay::todayAsDate();

        // Stamped identically on every row in the run, so a run is one moment
        // rather than a smear across however long the sweep took.
        $sweptAt = OperationalDay::today();

        // Ties the per-unit rows to their run entry.
        $runId = (string) Str::uuid();

        $expired = 0;
        $countsByFacility = [];

        // Before anything is expired: give up holds whose units have run out of
        // date while reserved. Until the allocation module existed this was an
        // acknowledged gap — a reserved unit could pass its expiry and keep
        // saying `reserved` forever, because the sweep touches `available` only
        // and nothing could release it. Releasing the hold returns the unit to
        // available, and the sweep below then expires it in the same run.
        $releasedHolds = $this->releaseExpiredHolds($today, $runId);

        // chunkById, not chunk: the sweep mutates the very column its outer
        // query filters on, so an offset-paged chunk() would skip rows as the
        // result set shrinks underneath it. The ascending id cursor also means
        // the sweep takes its locks in a consistent direction.
        $this->inventoryRepository->dueUnits($today)->chunkById(
            self::CHUNK,
            function (Collection $candidates) use ($today, $sweptAt, $runId, &$expired, &$countsByFacility): void {
                DB::transaction(function () use ($candidates, $today, $sweptAt, $runId, &$expired, &$countsByFacility): void {
                    // Re-read under FOR UPDATE, re-asserting both predicates.
                    // Anything a staff member changed since the candidate select
                    // fails them here and is dropped.
                    $confirmed = $this->inventoryRepository->lockConfirmedDueUnits(
                        $candidates->pluck('id')->all(),
                        $today,
                    );

                    if ($confirmed->isEmpty()) {
                        return;
                    }

                    $affected = $this->inventoryRepository->markExpired(
                        $confirmed->pluck('id')->all(),
                        $today,
                        $sweptAt,
                    );

                    // The audit rows describe this UPDATE. If they ever
                    // disagree, stop the run rather than write a trail that does
                    // not match the table.
                    if ($affected !== $confirmed->count()) {
                        throw new RuntimeException(
                            "expiry sweep {$runId}: updated {$affected} of {$confirmed->count()}"
                        );
                    }

                    foreach ($confirmed as $unit) {
                        $this->auditLogger->record(null, 'inventory.expired', $unit, [
                            'facility_id' => $unit->facility_id,
                            'operational_date' => $today,
                            'expiry_date' => $unit->expiry_date?->toDateString(),
                            'previous_status' => BloodUnitStatus::Available->value,
                            'source' => 'schedule:inventory:expire-units',
                            'run_id' => $runId,
                        ]);

                        $countsByFacility[$unit->facility_id] =
                            ($countsByFacility[$unit->facility_id] ?? 0) + 1;
                    }

                    $expired += $confirmed->count();
                });
            }
        );

        // Written on every run, including ones that expire nothing: a dead
        // scheduler and a quiet day would otherwise look identical in
        // audit_logs. One row a day is not a volume problem.
        $this->auditLogger->record(null, 'inventory.expiry_swept', null, [
            'run_id' => $runId,
            'operational_date' => $today,
            'expired_count' => $expired,
            'released_hold_count' => $releasedHolds,
            'by_facility' => $countsByFacility,
            'source' => 'schedule:inventory:expire-units',
        ]);

        $this->info("Expired {$expired} blood unit(s) as of {$today}.");

        if ($releasedHolds > 0) {
            $this->info("Released {$releasedHolds} expired hold(s) back to stock first.");
        }

        return self::SUCCESS;
    }

    /**
     * Give up holds whose units have passed their expiry, returning them to stock.
     *
     * The hold has to go before the unit can be expired: a unit that is still
     * promised to a request cannot simply be relabelled expired underneath the
     * promise, or the request would point at stock nobody can ever issue.
     *
     * The requesting facility is not notified here. What they need to know is
     * that their request is short again, which the outstanding count on the
     * request already tells them; a notification per expired bag would be noise
     * generated by a clock rather than by a decision anybody made.
     */
    private function releaseExpiredHolds(string $operationalDate, string $runId): int
    {
        return DB::transaction(function () use ($operationalDate, $runId): int {
            $holds = $this->inventoryRepository->lockExpiredHolds($operationalDate);

            if ($holds->isEmpty()) {
                return 0;
            }

            $unitIds = $holds->pluck('unit_id')->all();
            $returned = $this->inventoryRepository->markAvailable($unitIds);

            if ($returned !== count($unitIds)) {
                throw new RuntimeException(
                    "expiry sweep {$runId}: returned {$returned} of ".count($unitIds).' held units'
                );
            }

            RequestAllocation::query()->whereIn('id', $holds->pluck('id'))->update([
                'status' => AllocationStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancellation_reason' => 'Unit passed its expiry date while reserved.',
            ]);

            foreach ($holds as $hold) {
                $this->auditLogger->record(null, 'allocation.expired_hold_released', $hold->unit, [
                    'request_id' => $hold->request_id,
                    'operational_date' => $operationalDate,
                    'source' => 'schedule:inventory:expire-units',
                    'run_id' => $runId,
                ]);
            }

            return count($unitIds);
        });
    }
}
