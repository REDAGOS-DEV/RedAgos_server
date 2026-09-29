<?php

namespace App\Console\Commands;

use App\Enums\BloodRequestStatus;
use App\Models\BloodRequest;
use App\Models\TransfusionRequest;
use App\Service\AuditLogger;
use App\Service\RequestStatusResolver;
use App\Service\TransfusionRequestResolver;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
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
 * Patient Transfusion Requests are settled after their facility allocations,
 * whose figures they are read from. The migration that folded every earlier
 * patient request under one leaves them all Pending; this is what gives each
 * the status its allocations justify.
 *
 * Run once after migrating. Safe to run again: a request already settled is
 * left exactly as it is. Rejected and cancelled requests are decisions, not
 * derivations, and are never touched.
 */
class ResettleBloodRequests extends Command
{
    private const CHUNK = 200;

    private const OPEN = [
        BloodRequestStatus::Pending->value,
        BloodRequestStatus::Processing->value,
        BloodRequestStatus::Partial->value,
    ];

    protected $signature = 'requests:resettle {--dry-run : Report what would change without saving it}';

    protected $description = 'Re-derive open blood request and Patient Transfusion Request statuses from released units';

    public function __construct(
        private readonly RequestStatusResolver $resolver,
        private readonly TransfusionRequestResolver $transfusionResolver,
        private readonly AuditLogger $auditLogger
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = [];

        // Allocations first: a requirement's figures are read from them.
        $this->sweep(
            BloodRequest::class,
            fn (BloodRequest $request): array => $this->resolver->derive($request),
            fn (BloodRequest $request): mixed => $this->resolver->settle($request),
            'request.resettled',
            $dryRun,
            $changed
        );

        $this->sweep(
            TransfusionRequest::class,
            fn (TransfusionRequest $request): array => $this->transfusionResolver->derive($request),
            fn (TransfusionRequest $request): mixed => $this->transfusionResolver->settle($request),
            'request.transfusion_resettled',
            $dryRun,
            $changed
        );

        if ($changed === []) {
            $this->info('Every open request already matches its fulfilment.');

            return self::SUCCESS;
        }

        $this->table(['Reference', 'Was', 'Now'], $changed);
        $this->info(($dryRun ? 'Would re-settle ' : 'Re-settled ').count($changed).' request(s).');

        return self::SUCCESS;
    }

    /**
     * Re-derive every open row of one kind, one row per transaction.
     *
     * @param  class-string<BloodRequest|TransfusionRequest>  $model
     * @param  Closure(Model): array{status: BloodRequestStatus, closed: bool}  $derive
     * @param  Closure(Model): mixed  $settle
     * @param  array<int, array<int, string>>  $changed
     */
    private function sweep(string $model, Closure $derive, Closure $settle, string $action, bool $dryRun, array &$changed): void
    {
        $model::query()
            ->whereIn('status', self::OPEN)
            ->select('id')
            ->chunkById(self::CHUNK, function (Collection $chunk) use ($model, $derive, $settle, $action, $dryRun, &$changed): void {
                foreach ($chunk as $row) {
                    DB::transaction(function () use ($model, $row, $derive, $settle, $action, $dryRun, &$changed): void {
                        $request = $model::query()->whereKey($row->id)->lockForUpdate()->first();

                        if ($request === null) {
                            return;
                        }

                        $from = $request->status;
                        $wasClosed = $request->closed_at !== null;

                        ['status' => $status, 'closed' => $closed] = $derive($request);

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

                        $settle($request);

                        $this->auditLogger->record(null, $action, $request, [
                            'reference_number' => $request->reference_number,
                            'from' => $from->value,
                            'to' => $request->status->value,
                            'source' => 'artisan:requests:resettle',
                        ]);
                    });
                }
            });
    }
}
