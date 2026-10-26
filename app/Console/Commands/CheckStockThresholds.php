<?php

namespace App\Console\Commands;

use App\Models\Facility;
use App\Service\StockThresholdService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Announce blood stocks that have fallen below their facility's minimum.
 *
 * Each facility is swept in its own transaction, which also writes the
 * notifications. A facility whose sweep throws rolls back alone: nothing in it
 * is marked alerted, so the next minute's run retries it, and the other
 * facilities in the same run are unaffected.
 */
class CheckStockThresholds extends Command
{
    protected $signature = 'inventory:check-thresholds';

    protected $description = 'Notify facilities whose blood stock has fallen below its minimum';

    public function handle(StockThresholdService $service): int
    {
        $now = now()->startOfSecond()->toImmutable();
        $runId = (string) Str::uuid();

        $alerted = 0;
        $recovered = 0;
        $failed = 0;

        foreach ($service->facilityIdsToSweep() as $facilityId) {
            $facility = Facility::query()->with('facilityType')->find($facilityId);

            if ($facility === null || ! $facility->status->canOperate()) {
                continue;
            }

            try {
                $result = $service->sweepFacility($facility, $now, $runId);
            } catch (Throwable $e) {
                $failed++;

                Log::warning('Stock threshold sweep failed; it will be retried on the next run.', [
                    'facility_id' => $facilityId,
                    'run_id' => $runId,
                    'error' => $e->getMessage(),
                ]);

                report($e);

                continue;
            }

            $alerted += $result['alerted'];
            $recovered += $result['recovered'];
        }

        $this->info("Alerted {$alerted} stock(s), ended {$recovered} episode(s), {$failed} facility sweep(s) failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
