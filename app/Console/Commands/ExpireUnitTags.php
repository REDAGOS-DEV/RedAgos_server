<?php

namespace App\Console\Commands;

use App\Enums\HospitalUnitStatus;
use App\Enums\UnitTagStatus;
use App\Enums\UntagReason;
use App\Models\BloodUnit;
use App\Models\HospitalUnit;
use App\Models\UnitTag;
use App\Repository\HospitalInventoryRepository;
use App\Service\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Untag hospital bags whose 24-hour crossmatch or transfusion period has run out.
 *
 * Tag Assigned past its crossmatch deadline becomes Untagged Assigned and the
 * bag, which never left storage, is available again. Tag Crossmatched past its
 * transfusion deadline becomes Untagged Crossmatched and the bag, which has,
 * becomes Pending Return until staff confirm it is back or discard it.
 *
 * Runs every minute. Correctness does not hang on that cadence — every staff
 * write refuses a tag at or past its deadline — so the minute only decides how
 * quickly a lapsed bag visibly frees up. Idempotent: a second run finds nothing
 * the first left behind.
 *
 * Follows ExpireBloodUnits: candidates are selected unlocked, then each chunk
 * re-reads them under FOR UPDATE and re-asserts status and deadline, and the
 * update and its audit rows commit together. A staff member's crossmatch or
 * release that lands in between wins, and the sweep leaves that tag alone.
 */
class ExpireUnitTags extends Command
{
    /**
     * How many candidates each locked transaction handles.
     */
    private const CHUNK = 500;

    protected $signature = 'hospital:expire-tags';

    protected $description = 'Untag hospital blood units whose 24-hour crossmatch or transfusion period has ended';

    public function __construct(
        private readonly HospitalInventoryRepository $repository,
        private readonly AuditLogger $auditLogger
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // One moment for the whole run, truncated to the second the columns
        // hold, so "at the deadline" means the same thing here as in a write.
        $now = now()->startOfSecond()->toImmutable();
        $runId = (string) Str::uuid();

        $untagged = [
            UnitTagStatus::UntaggedAssigned->value => 0,
            UnitTagStatus::UntaggedCrossmatched->value => 0,
        ];
        $countsByFacility = [];

        // chunkById for the reason ExpireBloodUnits gives: the run rewrites the
        // very column its outer query filters on.
        $this->repository->overdueTags($now)->chunkById(
            self::CHUNK,
            function (Collection $candidates) use ($now, $runId, &$untagged, &$countsByFacility): void {
                DB::transaction(function () use ($candidates, $now, $runId, &$untagged, &$countsByFacility): void {
                    // The bags first, then their tags: the order every staff
                    // write takes, so the sweep and a write cannot deadlock.
                    $units = $this->repository->lockUnitsById(
                        $candidates->pluck('hospital_unit_id')->unique()->values()->all()
                    )->keyBy('id');

                    $confirmed = $this->repository->lockConfirmedOverdueTags($candidates->pluck('id')->all(), $now);

                    if ($confirmed->isEmpty()) {
                        return;
                    }

                    $bags = BloodUnit::query()->whereIn('id', $units->pluck('unit_id'))->get()->keyBy('id');

                    foreach ($confirmed->groupBy(fn (UnitTag $tag): string => $tag->status->value) as $status => $tags) {
                        $from = UnitTagStatus::from($status);
                        $this->untagGroup($from, $tags, $units, $bags, $now, $runId);

                        $untagged[$from->untaggedState()->value] += $tags->count();

                        foreach ($tags as $tag) {
                            $countsByFacility[$tag->facility_id] = ($countsByFacility[$tag->facility_id] ?? 0) + 1;
                        }
                    }
                });
            }
        );

        $total = array_sum($untagged);

        // Only when something moved. At one run a minute, a row for every
        // quiet run would be 1,440 rows a day saying nothing; the daily
        // hospital:expire-units run row is what proves the scheduler is alive.
        if ($total > 0) {
            $this->auditLogger->record(null, 'hospital_inventory.tag_sweep', null, [
                'run_id' => $runId,
                'swept_at' => $now->toIso8601String(),
                'untagged_count' => $total,
                'by_status' => $untagged,
                'by_facility' => $countsByFacility,
                'source' => 'schedule:hospital:expire-tags',
            ]);
        }

        $this->info("Untagged {$total} hospital blood unit tag(s) as of {$now->toDateTimeString()}.");

        return self::SUCCESS;
    }

    /**
     * End one status's worth of confirmed overdue tags and move their bags.
     *
     * @param  Collection<int, UnitTag>  $tags
     * @param  Collection<int, HospitalUnit>  $units
     * @param  Collection<string, BloodUnit>  $bags
     */
    private function untagGroup(
        UnitTagStatus $from,
        Collection $tags,
        Collection $units,
        Collection $bags,
        CarbonImmutable $now,
        string $runId
    ): void {
        [$unitFrom, $unitTo] = $from === UnitTagStatus::TagCrossmatched
            ? [HospitalUnitStatus::TagCrossmatched, HospitalUnitStatus::PendingReturn]
            : [HospitalUnitStatus::TagAssigned, HospitalUnitStatus::Available];

        $affected = $this->repository->markTagsUntagged($tags->pluck('id')->all(), $from, $now);

        // The audit rows describe these UPDATEs. If either disagrees with what
        // was confirmed under lock, stop rather than write a trail that does
        // not match the tables.
        if ($affected !== $tags->count()) {
            throw new RuntimeException("tag sweep {$runId}: untagged {$affected} of {$tags->count()} {$from->value} tag(s)");
        }

        $moved = $this->repository->markUnits($tags->pluck('hospital_unit_id')->all(), $unitFrom, $unitTo, $now);

        if ($moved !== $tags->count()) {
            throw new RuntimeException("tag sweep {$runId}: moved {$moved} of {$tags->count()} bag(s) from {$unitFrom->value}");
        }

        foreach ($tags as $tag) {
            $unit = $units->get($tag->hospital_unit_id);

            $this->auditLogger->record(null, 'hospital_inventory.untagged', $bags->get($unit?->unit_id), [
                'facility_id' => $tag->facility_id,
                'hospital_unit_id' => $tag->hospital_unit_id,
                'tag_id' => $tag->id,
                'transfusion_request_id' => $tag->transfusion_request_id,
                'previous_status' => $unitFrom->value,
                'new_status' => $unitTo->value,
                'tag_status' => $from->untaggedState()?->value,
                'deadline_at' => $tag->activeDeadline()?->toIso8601String(),
                'untag_reason' => UntagReason::deadlineFor($from)?->value,
                'source' => 'schedule:hospital:expire-tags',
                'run_id' => $runId,
            ]);
        }
    }
}
