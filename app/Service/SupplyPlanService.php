<?php

namespace App\Service;

use App\Enums\HandoverMethod;
use App\Enums\RequestEventType;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\SupplyPlan;
use App\Models\User;
use App\Repository\BloodRequestRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A blood centre's answer to a replenishment order, before anything is reserved for it.
 *
 * A restock is not met the way a patient's request is (project owner,
 * 2026-10-29). The centre first says how many units of each line it can
 * supply, whether the hospital picks the order up or the centre delivers it,
 * and when; only then are bags reserved, and never more of a line than was
 * committed (RequestStatusResolver caps the line's allocatable figure).
 *
 * The plan may be changed while nothing has gone out — a delivery moved to
 * the afternoon, a line cut to what is left on the shelf — but never below
 * what is already reserved for a line, and not at all once units are
 * dispatched. Whatever the commitments leave out is closed as not supplied
 * when the order is dispatched, as any unsupplied remainder of a weekly order
 * is (FulfillmentService::release). The hospital is told each time.
 */
class SupplyPlanService
{
    /**
     * How far ahead a hand-over may be set. A weekly order is met within days;
     * a date further out is a typing slip.
     */
    public const HORIZON_DAYS = 60;

    public function __construct(
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly RequestStatusResolver $resolver,
        private readonly BloodRequestHistory $history,
        private readonly BloodRequestNotifier $notifier,
        private readonly BloodRequestProjector $projector,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Confirm a replenishment order, or change how it will be met.
     *
     * @param  array<string, mixed>  $payload  validated by SaveSupplyPlanRequest
     * @return array<string, mixed>
     */
    public function save(User $user, int $requestId, array $payload): array
    {
        $facilityId = $this->requireFacilityId($user);
        $scheduledAt = $this->scheduledAt((string) $payload['scheduled_at']);
        $method = HandoverMethod::from((string) $payload['handover_method']);

        [$request, $first, $context] = DB::transaction(function () use ($user, $requestId, $facilityId, $payload, $scheduledAt, $method): array {
            $request = $this->bloodRequestRepository->lockAddressedTo($requestId, $facilityId)
                ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

            if (! $request->isReplenishment()) {
                throw $this->refuse(
                    409,
                    'not_replenishment',
                    "A patient's request is approved and reserved, not planned. Only a replenishment order takes a supply plan."
                );
            }

            if ($request->isClosed()) {
                throw $this->refuse(
                    409,
                    'request_closed',
                    "This order is {$request->status->label()} and can no longer be planned."
                );
            }

            $figures = $this->resolver->freshFigures($request);

            if ($figures->sum('fulfilled') > 0) {
                throw $this->refuse(
                    409,
                    'supply_plan_locked',
                    'Units on this order have already been dispatched, so how it is supplied can no longer change.'
                );
            }

            $commitments = $this->commitments($request, $figures, $payload['lines']);

            /** @var SupplyPlan|null $plan */
            $plan = $request->supplyPlan()->lockForUpdate()->first();
            $first = $plan === null;
            $before = $first ? null : $this->summary($plan, $request);

            $plan ??= new SupplyPlan(['request_id' => $request->id, 'planned_by' => $user->id]);
            $plan->fill([
                'handover_method' => $method,
                'scheduled_at' => $scheduledAt,
                'handover_address' => $this->clean($payload['handover_address'] ?? null),
                'contact_name' => $this->clean($payload['contact_name'] ?? null),
                'contact_phone' => $this->clean($payload['contact_phone'] ?? null),
                'notes' => $this->clean($payload['notes'] ?? null),
            ]);
            $plan->updated_by = $user->id;
            $plan->save();

            foreach ($request->items as $item) {
                if (array_key_exists($item->id, $commitments)) {
                    $item->committed_quantity = $commitments[$item->id];
                    $item->save();
                }
            }

            $request->setRelation('supplyPlan', $plan);
            $after = $this->summary($plan, $request->load('items'));

            $this->history->record(
                $request,
                $first ? RequestEventType::SupplyPlanned : RequestEventType::SupplyPlanChanged,
                $user,
                $request->status,
                $this->describe($after),
                meta: array_filter(['plan' => $after, 'previous' => $before]),
            );

            $this->auditLogger->record($user, 'supply_plan.saved', $request, [
                'facility_id' => $facilityId,
                'reference_number' => $request->reference_number,
                'plan' => $after,
                'previous' => $before,
            ]);

            return [$request, $first, $after];
        });

        // After the commit: a notification failure must not undo the plan.
        $this->notifier->requester($request, $first ? 'planned' : 'replanned', $context);

        $this->bloodRequestRepository->loadForProjection($request);
        $hospital = $request->requestingFacility?->name ?? 'The hospital';

        return [
            'message' => $first
                ? "Order confirmed. {$hospital} has been told what to expect."
                : "Supply plan updated. {$hospital} has been told.",
            'request' => $this->projector->project($request),
        ];
    }

    /**
     * Each open line's commitment, judged against what it asked for and what is already held.
     *
     * Every line still open must be given one, and a closed line takes none:
     * its remainder is already settled. A line may not be committed below the
     * units already reserved for it — those are returned to stock first — nor
     * above what it asked for. Committing nothing at all is a rejection, and
     * is refused as one.
     *
     * @param  Collection<int, array<string, mixed>>  $figures
     * @param  array<int, array{request_item_id: int, committed_quantity: int}>  $lines
     * @return array<int, int> request_item_id => committed quantity
     */
    private function commitments(BloodRequest $request, Collection $figures, array $lines): array
    {
        $items = $request->items->keyBy('id');
        $commitments = [];
        $errors = [];

        foreach (array_values($lines) as $index => $line) {
            $itemId = (int) $line['request_item_id'];
            $quantity = (int) $line['committed_quantity'];
            /** @var BloodRequestItem|null $item */
            $item = $items->get($itemId);

            if ($item === null) {
                $errors["lines.{$index}.request_item_id"] = ['That line is not on this order.'];

                continue;
            }

            if ($item->closed_at !== null) {
                $errors["lines.{$index}.request_item_id"] = ['That line is closed; nothing more is supplied on it.'];

                continue;
            }

            $figure = $figures->get($itemId);
            $held = (int) ($figure['reserved'] ?? 0) + (int) ($figure['fulfilled'] ?? 0);

            if ($quantity > $item->quantity) {
                $errors["lines.{$index}.committed_quantity"] = ["No more than the {$item->quantity} asked for."];
            } elseif ($quantity < $held) {
                $errors["lines.{$index}.committed_quantity"] = ["{$held} already reserved for this line; return some to stock first."];
            }

            $commitments[$itemId] = $quantity;
        }

        $missing = $items->filter(fn (BloodRequestItem $item): bool => $item->closed_at === null && ! array_key_exists($item->id, $commitments));

        if ($missing->isNotEmpty()) {
            $errors['lines'] = ['Say how many units you will supply of every line on the order.'];
        } elseif ($errors === [] && array_sum($commitments) < 1) {
            $errors['lines'] = ['Commit at least one unit. If you can supply none of this order, reject it instead.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $commitments;
    }

    /**
     * The hand-over time, in the app's zone, from today's operational day to the horizon.
     */
    private function scheduledAt(string $value): CarbonImmutable
    {
        $at = CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
        $today = OperationalDay::today()->startOfDay();

        if ($at->lt($today)) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The pick-up or delivery cannot be on a day that has passed.'],
            ]);
        }

        if ($at->gt($today->addDays(self::HORIZON_DAYS)->endOfDay())) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['Set a pick-up or delivery within the next '.self::HORIZON_DAYS.' days.'],
            ]);
        }

        return $at;
    }

    /**
     * The plan as the history, the audit log and the hospital's notice record it.
     *
     * @return array<string, mixed>
     */
    private function summary(SupplyPlan $plan, BloodRequest $request): array
    {
        return [
            'handover_method' => $plan->handover_method->value,
            'scheduled_at' => $plan->scheduled_at->toIso8601String(),
            'handover_address' => $plan->handover_address,
            'committed' => (int) $request->items->sum('committed_quantity'),
            'requested' => (int) $request->items->sum('quantity'),
            'lines' => $request->items->sortBy('id')->mapWithKeys(
                fn (BloodRequestItem $item): array => [$item->id => $item->committed_quantity]
            )->all(),
        ];
    }

    /**
     * "Delivery on Fri 30 Oct 2026, 9:00 AM · 8 of 10 units", for the request's history.
     *
     * @param  array<string, mixed>  $summary
     */
    private function describe(array $summary): string
    {
        $method = HandoverMethod::from($summary['handover_method']);
        $when = CarbonImmutable::parse($summary['scheduled_at'])
            ->setTimezone((string) config('blood_center.timezone', 'Asia/Manila'))
            ->format('D j M Y, g:i A');

        return "{$method->label()} on {$when} · {$summary['committed']} of {$summary['requested']} unit(s).";
    }

    private function clean(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Resolve the caller's facility, refusing a staff account without one.
     */
    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw $this->refuse(
            404,
            'facility_missing',
            'This account is not linked to a facility.'
        );
    }

    /**
     * Build the project's standard refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
