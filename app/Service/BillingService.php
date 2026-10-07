<?php

namespace App\Service;

use App\Enums\BillingStatus;
use App\Enums\CorrectionSubject;
use App\Enums\PaymentStatus;
use App\Models\Billing;
use App\Models\BloodRequest;
use App\Models\CorrectionRequest;
use App\Models\Payment;
use App\Models\User;
use App\Repository\BloodComponentRepository;
use App\Repository\BloodRequestRepository;
use App\Support\CorrectionValues;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The statement raised against a blood request, and what it collects.
 *
 * Blood requests are presently funded by government subsidy, so every
 * statement this raises comes to zero and is settled on creation. That is a
 * pricing fact, not an architectural one: the charge is computed from
 * the fulfilling facility's own price for the component, which is unset today.
 * Setting it turns the release gate on by itself, with no change here or in
 * FulfillmentService — which is why the gate is written and tested now rather
 * than deferred until money appears. It is held per facility so that one centre
 * pricing its components cannot start blocking releases at the other three.
 *
 * Locking. Every change to a statement or to one of its payments runs inside
 * one transaction, and the blood request is the point they serialise on:
 * locks are always taken request, then billing, then payment. That is the
 * order release() already holds the request before it reads the statement, so
 * a payment, a subsidy, a reallocation, a correction and a release on one
 * request take turns instead of overwriting each other. A statement's status
 * is always worked out from the billing row and payments as they stand under
 * the lock, never from a model loaded before it — a stale one would put back a
 * status another writer had just changed. lockForMutation() takes the first
 * two locks; the writers that need a payment lock it after.
 */
class BillingService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly BloodComponentRepository $bloodComponentRepository,
        private readonly BloodRequestRepository $bloodRequestRepository
    ) {}

    /**
     * Lock a request and its statement for a change, in the one order every writer uses.
     *
     * Scoped to the facility the request is addressed to, so another centre's
     * request is a 404. Must be called inside a transaction: outside one the
     * locks are released the moment each query returns.
     *
     * @return array{0: BloodRequest, 1: Billing}
     */
    public function lockForMutation(int $requestId, int $facilityId): array
    {
        $request = $this->bloodRequestRepository->lockAddressedTo($requestId, $facilityId)
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        $billing = Billing::query()
            ->where('request_id', $request->id)
            ->lockForUpdate()
            ->first()
            ?? throw $this->refuse(404, 'billing_missing', 'No statement has been raised for this request yet.');

        return [$request, $billing];
    }

    /**
     * Raise or update the statement for a request's currently held units.
     *
     * Called on every allocation, because billings.request_id is unique: a
     * request that grows from two held units to five needs its one statement
     * to grow with it rather than a second statement it cannot have.
     *
     * The held count is read from the allocations rather than passed in. Once a
     * request can ask for several components at different prices, a bare number
     * of units no longer determines what is owed — five units of platelets and
     * five of packed cells are two different totals.
     *
     * Must be called inside a transaction that already holds the request lock,
     * as the allocating one does. The statement is then locked here, which
     * completes request, then billing.
     */
    public function syncFor(BloodRequest $request, User $actor): Billing
    {
        $claimedUnits = $this->claimedPerComponent($request);
        $total = $this->totalFor($request, $claimedUnits);

        $billing = $request->billing()->lockForUpdate()->first();

        if (! $billing) {
            $billing = Billing::query()->create([
                'request_id' => $request->id,
                'billed_by' => $actor->id,
                'billing_date' => now(),
                'total_amount' => $total,
                'status' => $this->settlementFor($total, 0.0),
            ]);

            $this->auditLogger->record($actor, 'billing.raised', $billing, [
                'request_id' => $request->id,
                'total_amount' => $total,
                'units' => array_sum($claimedUnits),
                'subsidised' => $total === 0.0,
            ]);

            return $billing;
        }

        // A statement settled by decision is left alone. Voiding says it
        // should never have existed; subsidy says the government met the cost.
        // Either way somebody decided nothing is owed, and silently re-pricing
        // because another unit was allocated would undo that — and would
        // re-block release on a request already cleared to go.
        if ($billing->status->isSettledByDecision()) {
            return $billing;
        }

        $collected = $this->collectedFor($billing);

        $billing->total_amount = $total;
        $billing->status = $this->settlementFor($total, $collected);
        $billing->save();

        $this->auditLogger->record($actor, 'billing.updated', $billing, [
            'request_id' => $request->id,
            'total_amount' => $total,
            'collected' => $collected,
            'units' => array_sum($claimedUnits),
        ]);

        return $billing;
    }

    /**
     * Count the units currently claimed against each of a request's components.
     *
     * Keyed by component id rather than line id: two lines cannot name the same
     * component, and the price is a property of the component.
     *
     * @return array<int, int>
     */
    private function claimedPerComponent(BloodRequest $request): array
    {
        $lineComponents = $request->items->pluck('component_id', 'id');

        return $request->allocations()->claiming()
            ->groupBy('request_item_id')
            ->selectRaw('request_item_id, COUNT(*) as held')
            ->pluck('held', 'request_item_id')
            ->reduce(function (array $carry, $held, $itemId) use ($lineComponents): array {
                $componentId = $lineComponents[$itemId] ?? null;

                if ($componentId !== null) {
                    $carry[$componentId] = ($carry[$componentId] ?? 0) + (int) $held;
                }

                return $carry;
            }, []);
    }

    /**
     * Price a request's held units at the fulfilling facility's own rates.
     *
     * Priced by the facility fulfilling the request, not by the shared
     * blood_components row. target_facility_id, never facility_id: the former
     * is who was asked and supplies the blood, the latter is the hospital that
     * asked. An unset price is zero, which leaves the payment-before-release
     * gate off — so one centre setting a price can no longer start blocking
     * releases at another.
     *
     * @param  array<int, int>  $claimedUnits
     */
    private function totalFor(BloodRequest $request, array $claimedUnits): float
    {
        if ($request->target_facility_id === null) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($claimedUnits as $componentId => $units) {
            $unitPrice = (float) ($this->bloodComponentRepository
                ->setting((int) $request->target_facility_id, (int) $componentId)
                ?->price ?? 0);

            $total += $unitPrice * $units;
        }

        return round($total, 2);
    }

    /**
     * List the statements raised against one facility's incoming requests.
     *
     * Scoped through the request's target facility, exactly as show() is: a
     * statement belongs to the centre that raised it, and billing staff at one
     * centre have no business reading another's.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return Billing::query()
            ->whereHas('request', fn (Builder $query) => $query->addressedTo($facilityId))
            ->with(['request.requestingFacility', 'request.items.component', 'request.bloodType'])
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('status', $filters['status'])
            )
            ->when(
                isset($filters['outstanding']) && $filters['outstanding'],
                fn (Builder $query): Builder => $query->outstanding()
            )
            ->when(
                isset($filters['search']),
                fn (Builder $query): Builder => $query->whereHas(
                    'request',
                    fn (Builder $request): Builder => $request->where(
                        'reference_number', 'like', '%'.$filters['search'].'%'
                    )
                )
            )
            ->latest('billing_date')
            ->paginate($perPage)
            ->through(fn (Billing $billing): array => $this->formatWithRequest($billing));
    }

    /**
     * Project a statement together with enough of its request to be actionable.
     *
     * The billing queue is a list of statements, but a billing officer settles
     * them by reading the request: whose it is, what was asked for, and how
     * much of it is held. Those come from the request, not the statement.
     *
     * @return array<string, mixed>
     */
    public function formatWithRequest(Billing $billing): array
    {
        $request = $billing->request;

        return $this->format($billing) + [
            'request' => $request ? [
                'id' => $request->id,
                'reference_number' => $request->reference_number,
                'status' => $request->status->value,
                'status_label' => $request->status->label(),
                'requesting_facility' => $request->requestingFacility?->name,
                'blood_type' => $request->bloodType?->code,
                'quantity' => $request->quantity,
                'urgency_level' => $request->urgency_level->value,
                'is_emergency' => $request->urgency_level->isPrioritised(),
                'components' => $request->items
                    ->map(fn ($item): string => trim(($item->component?->name ?? 'Component').' x'.$item->quantity))
                    ->all(),
                'request_date' => $request->request_date?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Meet a statement from the government subsidy rather than charging for it.
     *
     * Zeroes the balance and clears the request for release without recording a
     * payment, because none was taken. Any money already collected is left
     * alone: a part-paid statement that is then subsidised has a refund to
     * settle outside this system, and quietly deleting the payment rows would
     * destroy the only record that it was ever received.
     *
     * @return array<string, mixed>
     */
    public function applySubsidy(User $actor, Billing $billing, ?string $reason = null): array
    {
        return DB::transaction(function () use ($actor, $billing, $reason): array {
            // The statement the caller loaded may be out of date by now. Every
            // check and every figure below reads the locked row instead.
            [, $statement] = $this->lockForMutation((int) $billing->request_id, (int) $actor->facility_id);

            if ($statement->status === BillingStatus::Subsidised) {
                throw $this->refuse(
                    409,
                    'billing_already_subsidised',
                    'This statement is already covered by the government subsidy.'
                );
            }

            if ($statement->status === BillingStatus::Void) {
                throw $this->refuse(
                    409,
                    'billing_void',
                    'This statement has been voided and cannot be subsidised.'
                );
            }

            $charged = (float) $statement->total_amount;
            $collected = $this->collectedFor($statement);

            $statement->total_amount = 0;
            $statement->status = BillingStatus::Subsidised;
            $statement->save();

            $this->auditLogger->record($actor, 'billing.subsidised', $statement, array_filter([
                'request_id' => $statement->request_id,
                // The figure that was waived, kept because the statement no longer
                // carries it and a subsidy nobody can size cannot be reported on.
                'amount_waived' => $charged,
                'already_collected' => $collected,
                'reason' => $reason,
            ], fn ($value): bool => $value !== null));

            return [
                'message' => $collected > 0.0
                    ? 'Statement covered by the government subsidy. Payments already recorded are unchanged.'
                    : 'Statement covered by the government subsidy.',
                'billing' => $this->format($statement->fresh()),
            ];
        });
    }

    /**
     * Refuse release unless the request's statement is settled.
     *
     * The Capstone states twice that no unit leaves without confirmed payment.
     * Under the present subsidy every statement is zero and already paid, so
     * this passes silently — but it is the real gate, not a placeholder, and it
     * starts refusing the moment a component carries a price.
     *
     * Reads the statement without locking it: its caller, release(), already
     * holds the request, and every writer takes the request first, so what it
     * reads cannot change underneath it. The gate is judged at the moment of
     * dispatch. A payment correction approved afterwards can reopen the
     * balance, and that is surfaced as outstanding rather than undone.
     */
    public function assertClearsRelease(BloodRequest $request): void
    {
        $billing = $request->billing()->first();

        if (! $billing) {
            throw $this->refuse(
                409,
                'billing_missing',
                'No statement has been raised for this request, so it cannot be released.'
            );
        }

        if (! $billing->clearsRelease()) {
            throw $this->refuse(
                409,
                'billing_unsettled',
                'Blood units cannot be released until payment for this request is confirmed.'
            );
        }
    }

    /**
     * Record a settlement against a statement.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recordPayment(User $actor, Billing $billing, array $payload): array
    {
        return DB::transaction(function () use ($actor, $billing, $payload): array {
            // Worked out from the locked statement, not the one the caller
            // loaded: a correction approved a moment ago may have changed
            // what has been collected, and this must not put the old status back.
            [, $statement] = $this->lockForMutation((int) $billing->request_id, (int) $actor->facility_id);

            $payment = Payment::query()->create([
                'billing_id' => $statement->id,
                'amount_paid' => $payload['amount_paid'],
                'payment_method' => $payload['payment_method'],
                'reference_number' => $payload['reference_number'] ?? null,
                'status' => $payload['status'] ?? PaymentStatus::Completed,
                'payment_date' => now(),
            ]);

            $collected = $this->collectedFor($statement);

            $statement->status = $this->settlementFor((float) $statement->total_amount, $collected);
            $statement->save();

            $this->auditLogger->record($actor, 'billing.payment_recorded', $statement, [
                'payment_id' => $payment->id,
                'amount_paid' => (float) $payment->amount_paid,
                'payment_method' => $payment->payment_method->value,
                'collected' => $collected,
                'status' => $statement->status->value,
            ]);

            return [
                'message' => 'Payment recorded.',
                'billing' => $this->format($statement->fresh()),
            ];
        });
    }

    /**
     * Apply an approved correction to one recorded payment.
     *
     * Only what was recorded can change — the amount, the method and the
     * reference — never the payment's status: a correction fixes a mistake, it
     * is not a way to turn money received into money refused. The statement is
     * then re-settled from what it has now collected, unless somebody decided
     * it (void, subsidised), which a payment never overrides.
     *
     * A correction approved after the units were released can reopen the
     * statement. That is deliberate: the figure on the statement has to be true.
     * The release gate asks about payment at the moment of dispatch and not
     * again, so the reopened balance is surfaced as outstanding instead, and
     * the audit entry names the request's status at the time.
     *
     * Must be called inside a transaction that already holds the request, then
     * the billing row, then the payment — see lockForMutation(). Nothing here
     * locks.
     *
     * @param  array<string, mixed>  $changes  Already validated and normalised.
     */
    public function correctPayment(User $actor, BloodRequest $lockedRequest, Billing $lockedBilling, Payment $lockedPayment, array $changes): void
    {
        if ($lockedBilling->status === BillingStatus::Void) {
            throw $this->refuse(409, 'billing_void', 'This statement has been voided, so its payments can no longer be corrected.');
        }

        // A correction carries the whole corrected payload, so what is audited
        // is the fields whose value actually differs, in canonical form.
        $types = CorrectionSubject::Payment->fieldTypes();
        $current = CorrectionValues::normalizeAll([
            'amount_paid' => $lockedPayment->amount_paid,
            'payment_method' => $lockedPayment->payment_method?->value,
            'reference_number' => $lockedPayment->reference_number,
        ], $types);

        $fields = [];

        foreach ($types as $field => $type) {
            if (array_key_exists($field, $changes) && ! CorrectionValues::same($current[$field] ?? null, $changes[$field], $type)) {
                $lockedPayment->{$field} = $changes[$field];
                $fields[] = $field;
            }
        }

        try {
            $lockedPayment->save();
        } catch (QueryException $exception) {
            // The validator checked the reference a moment ago; this is the
            // backstop for another payment taking it in between.
            if (in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                throw ValidationException::withMessages([
                    'changes.reference_number' => ['That payment reference has already been recorded.'],
                ]);
            }

            throw $exception;
        }

        $statusFrom = $lockedBilling->status;

        if (! $statusFrom->isSettledByDecision()) {
            $lockedBilling->status = $this->settlementFor(
                (float) $lockedBilling->total_amount,
                $this->collectedFor($lockedBilling)
            );
            $lockedBilling->save();
        }

        $this->auditLogger->record($actor, 'billing.payment_corrected', $lockedBilling, [
            'request_id' => $lockedRequest->id,
            'payment_id' => $lockedPayment->id,
            'fields' => $fields,
            'status_from' => $statusFrom->value,
            'status_to' => $lockedBilling->status->value,
            'request_status' => $lockedRequest->status->value,
        ]);
    }

    /**
     * The payments recorded against a statement, as the one viewing them may act on each.
     *
     * Separate from format() on purpose. billing.view is held by roles that
     * only need to know whether a release is cleared, and a payment carries a
     * reference number they have no business reading; this is reached only
     * through billing.record_payment.
     *
     * can_request_correction and pending_correction describe the viewer and are
     * there to draw the screen. CorrectionService::request() enforces the same
     * rules on its own.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payments(Billing $billing, User $viewer): array
    {
        $payments = $billing->payments()->orderBy('payment_date')->orderBy('id')->get();

        $pending = CorrectionRequest::query()
            ->where('subject', CorrectionSubject::Payment->value)
            ->where('status', 'pending')
            ->whereIn('payment_id', $payments->pluck('id'))
            ->pluck('payment_id')
            ->all();

        $mayFile = $billing->status !== BillingStatus::Void
            && CorrectionSubject::Payment->mayBeFiledBy($viewer);

        return $payments->map(function (Payment $payment) use ($pending, $mayFile): array {
            $isPending = in_array($payment->id, $pending, true);

            return [
                'id' => $payment->id,
                'amount_paid' => (float) $payment->amount_paid,
                'payment_method' => $payment->payment_method->value,
                'payment_method_label' => $payment->payment_method->label(),
                'reference_number' => $payment->reference_number,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'payment_date' => $payment->payment_date?->toIso8601String(),
                'pending_correction' => $isPending,
                'can_request_correction' => $mayFile && ! $isPending,
            ];
        })->all();
    }

    /**
     * Project a statement for the API.
     *
     * @return array<string, mixed>
     */
    public function format(Billing $billing): array
    {
        return [
            'id' => $billing->id,
            'request_id' => $billing->request_id,
            'total_amount' => (float) $billing->total_amount,
            'collected' => $this->collectedFor($billing),
            'status' => $billing->status->value,
            'status_label' => $billing->status->label(),
            'is_zero_rated' => $billing->isZeroRated(),
            'is_subsidised' => $billing->status === BillingStatus::Subsidised,
            // Distinguishes "nothing was owed" from "the money came in", which
            // a status alone cannot once a subsidy zeroes the total.
            'represents_collected_money' => $billing->status->representsCollectedMoney(),
            'clears_release' => $billing->clearsRelease(),
            'billing_date' => $billing->billing_date?->toIso8601String(),
        ];
    }

    /**
     * Sum what a statement has actually collected.
     *
     * Only completed payments count. A failed or refunded attempt is part of
     * the trail, never part of the total.
     */
    private function collectedFor(Billing $billing): float
    {
        return (float) $billing->payments()->collected()->sum('amount_paid');
    }

    /**
     * Decide a statement's settlement state from what it asks and what it has.
     *
     * A zero total is Paid rather than Unpaid: nothing is owed, so nothing is
     * outstanding, and treating it as unpaid would block every release under
     * the present subsidy.
     */
    private function settlementFor(float $total, float $collected): BillingStatus
    {
        if ($total <= 0.0 || $collected >= $total) {
            return BillingStatus::Paid;
        }

        return $collected > 0.0 ? BillingStatus::Partial : BillingStatus::Unpaid;
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
