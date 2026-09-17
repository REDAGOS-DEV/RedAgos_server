<?php

namespace App\Service;

use App\Enums\BillingStatus;
use App\Enums\PaymentStatus;
use App\Models\Billing;
use App\Models\BloodRequest;
use App\Models\Payment;
use App\Models\User;
use App\Repository\BloodComponentRepository;
use Illuminate\Http\Exceptions\HttpResponseException;

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
 */
class BillingService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly BloodComponentRepository $bloodComponentRepository
    ) {}

    /**
     * Raise or update the statement for a request's currently held units.
     *
     * Called on every allocation, because billings.request_id is unique: a
     * request that grows from two held units to five needs its one statement
     * to grow with it rather than a second statement it cannot have.
     *
     * Must be called inside the allocating transaction.
     */
    public function syncFor(BloodRequest $request, User $actor, int $claimedUnits): Billing
    {
        // Priced by the facility fulfilling the request, not by the shared
        // blood_components row. target_facility_id, never facility_id: the
        // former is who was asked and supplies the blood, the latter is the
        // hospital that asked. An unset price is zero, which leaves the
        // payment-before-release gate off — so one centre setting a price can
        // no longer start blocking releases at another.
        $unitPrice = $request->target_facility_id === null
            ? 0.0
            : (float) ($this->bloodComponentRepository
                ->setting((int) $request->target_facility_id, (int) $request->component_id)
                ?->price ?? 0);
        $total = round($unitPrice * $claimedUnits, 2);

        $billing = $request->billing()->first();

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
                'units' => $claimedUnits,
                'subsidised' => $total === 0.0,
            ]);

            return $billing;
        }

        // A voided statement is left alone. Voiding is a deliberate act saying
        // nothing is owed on this request, and silently reviving it because
        // another unit was allocated would undo somebody's decision.
        if ($billing->status === BillingStatus::Void) {
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
            'units' => $claimedUnits,
        ]);

        return $billing;
    }

    /**
     * Refuse release unless the request's statement is settled.
     *
     * The Capstone states twice that no unit leaves without confirmed payment.
     * Under the present subsidy every statement is zero and already paid, so
     * this passes silently — but it is the real gate, not a placeholder, and it
     * starts refusing the moment a component carries a price.
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
        $payment = Payment::query()->create([
            'billing_id' => $billing->id,
            'amount_paid' => $payload['amount_paid'],
            'payment_method' => $payload['payment_method'],
            'reference_number' => $payload['reference_number'] ?? null,
            'status' => $payload['status'] ?? PaymentStatus::Completed,
            'payment_date' => now(),
        ]);

        $collected = $this->collectedFor($billing->fresh());

        $billing->status = $this->settlementFor((float) $billing->total_amount, $collected);
        $billing->save();

        $this->auditLogger->record($actor, 'billing.payment_recorded', $billing, [
            'payment_id' => $payment->id,
            'amount_paid' => (float) $payment->amount_paid,
            'payment_method' => $payment->payment_method->value,
            'collected' => $collected,
            'status' => $billing->status->value,
        ]);

        return [
            'message' => 'Payment recorded.',
            'billing' => $this->format($billing->fresh()),
        ];
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
