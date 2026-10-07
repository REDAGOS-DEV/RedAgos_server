<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * How close, in seconds, an allocation's released_at must sit to the event
     * that released it. release() writes both inside one transaction.
     */
    private const WINDOW_SECONDS = 5;

    /**
     * How many ambiguous allocation ids the log entry names.
     */
    private const LOGGED_IDS = 100;

    /**
     * Copy who took each dispatched unit from the history onto its allocation.
     *
     * Before add_handed_to_to_request_allocations, release() wrote the name only
     * into the released event's meta. This reads those events and fills the new
     * column for the allocations they released, so a dispatch record shows the
     * name after deployment rather than only for releases made from now on.
     *
     * Data only, and decoded in PHP rather than with SQL JSON functions so the
     * same code runs on PostgreSQL and SQLite.
     *
     * Where the match is not certain the allocation is left null, and the
     * history event stays the authoritative record: its note still reads
     * "Handed to X.", and the request's timeline shows it.
     *  - one released allocation for the request and unit: filled;
     *  - several (a legacy re-release of one bag to the same request): the one
     *    whose released_at is closest to the event, within the window, and
     *    only if it is strictly the closest;
     *  - none: skipped, the allocation may since have been deleted.
     *
     * A migration cannot print to the console, so the counts go to the log.
     */
    public function up(): void
    {
        $filled = 0;
        $unmatched = 0;
        $ambiguous = [];

        DB::table('blood_request_events')
            ->where('event', 'released')
            ->whereNotNull('meta')
            ->orderBy('id')
            ->chunkById(200, function ($events) use (&$filled, &$unmatched, &$ambiguous): void {
                foreach ($events as $event) {
                    $handedTo = $this->handedTo($event->meta);
                    $unitIds = json_decode((string) $event->unit_ids, true);

                    if ($handedTo === null || ! is_array($unitIds)) {
                        continue;
                    }

                    foreach ($unitIds as $unitId) {
                        $this->backfillUnit($event, (string) $unitId, $handedTo, $filled, $unmatched, $ambiguous);
                    }
                }
            });

        Log::info('handed_to backfill', [
            'filled' => $filled,
            'ambiguous' => count($ambiguous),
            'unmatched' => $unmatched,
            'ambiguous_allocation_ids' => array_slice($ambiguous, 0, self::LOGGED_IDS),
        ]);
    }

    /**
     * Data only: dropping the column in add_handed_to_to_request_allocations
     * removes what this wrote, and the history events are untouched.
     */
    public function down(): void
    {
        //
    }

    private function handedTo(mixed $meta): ?string
    {
        $decoded = json_decode((string) $meta, true);

        if (! is_array($decoded) || ! isset($decoded['handed_to'])) {
            return null;
        }

        $name = trim((string) $decoded['handed_to']);

        return $name === '' ? null : $name;
    }

    /**
     * @param  array<int, int>  $ambiguous
     */
    private function backfillUnit(object $event, string $unitId, string $handedTo, int &$filled, int &$unmatched, array &$ambiguous): void
    {
        $candidates = DB::table('request_allocations')
            ->where('request_id', $event->request_id)
            ->where('unit_id', $unitId)
            ->where('status', 'released')
            ->whereNull('handed_to')
            ->get(['id', 'released_at']);

        if ($candidates->isEmpty()) {
            $unmatched++;

            return;
        }

        $target = $candidates->count() === 1 ? $candidates->first() : $this->closest($candidates, $event);

        if ($target === null) {
            foreach ($candidates as $candidate) {
                $ambiguous[] = (int) $candidate->id;
            }

            return;
        }

        DB::table('request_allocations')->where('id', $target->id)->update(['handed_to' => $handedTo]);

        $filled++;
    }

    /**
     * The one allocation released within the window of the event, if exactly one is nearest.
     */
    private function closest($candidates, object $event): ?object
    {
        if ($event->created_at === null) {
            return null;
        }

        $at = Carbon::parse($event->created_at)->getTimestamp();

        $scored = $candidates
            ->filter(fn (object $candidate): bool => $candidate->released_at !== null)
            ->map(fn (object $candidate): array => [
                'row' => $candidate,
                'gap' => abs(Carbon::parse($candidate->released_at)->getTimestamp() - $at),
            ])
            ->filter(fn (array $scored): bool => $scored['gap'] <= self::WINDOW_SECONDS)
            ->sortBy('gap')
            ->values();

        if ($scored->isEmpty()) {
            return null;
        }

        // Two equally close allocations cannot be told apart.
        if ($scored->count() > 1 && $scored[0]['gap'] === $scored[1]['gap']) {
            return null;
        }

        return $scored[0]['row'];
    }
};
