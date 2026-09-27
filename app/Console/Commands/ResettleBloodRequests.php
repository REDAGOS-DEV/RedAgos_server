<?php

namespace App\Console\Commands;

use App\Enums\BloodRequestStatus;
use App\Models\BloodRequest;
use App\Service\AuditLogger;
use App\Service\RequestStatusResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Re-derive every open request's status under the dispatch-based rule.
 *
 * Until RequestStatusResolver, a request only became Partially Fulfilled or
 * Fulfilled when the hospital confirmed receipt, so one whose units had all been
 * released but not yet received still said Processing. Fulfilment is now
 * counted at dispatch. Requests fix themselves the next time anything happens
 * to them; this brings the ones nothing has touched since into line at once.
 *
 * Run once after migrating. Safe to run again: a request already settled is
 * left exactly as it is. Rejected and cancelled requests are decisions, not
 * derivations, and are never touched.
 */
class ResettleBloodRequests extends Command
{
    private const CHUNK = 200;

    protected $signature = 'requests:resettle {--dry-run : Report what would change without saving it}';

    protected $description = 'Re-derive open blood request statuses from released units';

    public function __construct(
        private readonly RequestStatusResolver $resolver,
        private readonly AuditLogger $auditLogger
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = [];

        BloodRequest::query()
            ->whereIn('status', [
                BloodRequestStatus::Pending->value,
                BloodRequestStatus::Processing->value,
                BloodRequestStatus::Partial->value,
            ])
            ->select('id')
            ->chunkById(self::CHUNK, function (Collection $chunk) use ($dryRun, &$changed): void {
                foreach ($chunk as $row) {
                    DB::transaction(function () use ($row, $dryRun, &$changed): void {
                        $request = BloodRequest::query()->whereKey($row->id)->lockForUpdate()->first();

                        if ($request === null) {
                            return;
                        }

                        $from = $request->status;
                        $wasClosed = $request->closed_at !== null;

                        ['status' => $status, 'closed' => $closed] = $this->resolver->derive($request);

                        if ($status === $from && $closed === $wasClosed) {
                            return;
                        }

                        $changed[] = [
                            $request->reference_number,
                            $from->value.($wasClosed ? ' (closed)' : ''),
                            $status->value.($closed ? ' (closed)' : ''),
                        ];

                        if ($dryRun) {
                            return;
                        }

                        $this->resolver->settle($request);

                        $this->auditLogger->record(null, 'request.resettled', $request, [
                            'reference_number' => $request->reference_number,
                            'from' => $from->value,
                            'to' => $request->status->value,
                            'source' => 'artisan:requests:resettle',
                        ]);
                    });
                }
            });

        if ($changed === []) {
            $this->info('Every open request already matches its fulfilment.');

            return self::SUCCESS;
        }

        $this->table(['Reference', 'Was', 'Now'], $changed);
        $this->info(($dryRun ? 'Would re-settle ' : 'Re-settled ').count($changed).' request(s).');

        return self::SUCCESS;
    }
}
