<?php

namespace App\Service;

use App\Enums\BillingStatus;
use App\Enums\RequestPurpose;
use App\Models\Billing;
use App\Models\BloodRequest;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The billing counter: finding a patient's bill and putting it in front of the cashier.
 *
 * Read-only. Taking the money is BillingService::recordPayment() and the GCash
 * checkout, exactly as from the statements page; the counter adds the shift
 * those need and a faster way to the bill.
 *
 * Only Patient Transfusion bills come to the counter. A weekly order is the
 * hospital's, billed by statement and settled outside RedAgos, so it is never
 * found here.
 */
class PosService
{
    /**
     * How many bills one search shows.
     */
    private const LOOKUP_LIMIT = 10;

    /**
     * How many bills the awaiting-payment queue shows.
     */
    private const QUEUE_LIMIT = 25;

    public function __construct(
        private readonly BillingService $billingService,
        private readonly PaymentCheckoutService $checkout,
        private readonly StatementFigures $figures
    ) {}

    /**
     * Find this centre's patient bills by request or PTR reference, patient name, or walk-in reference.
     *
     * Bills still owing first, newest first within that.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lookup(User $staff, string $term): array
    {
        $facilityId = $this->requireFacilityId($staff);
        $like = '%'.trim($term).'%';

        return $this->counterBills($facilityId)
            ->where(fn (Builder $query) => $query
                ->where('reference_number', 'like', $like)
                ->orWhere('patient_surname', 'like', $like)
                ->orWhere('patient_first_name', 'like', $like)
                ->orWhereHas('transfusionRequest', fn (Builder $ptr) => $ptr->where('reference_number', 'like', $like))
                ->orWhereHas('walkIn', fn (Builder $walkIn) => $walkIn->where('presented_reference', 'like', $like)))
            ->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM billings b WHERE b.request_id = blood_requests.id AND b.status IN (?, ?)) THEN 0 ELSE 1 END', [
                BillingStatus::Unpaid->value,
                BillingStatus::Partial->value,
            ])
            ->orderByDesc('request_date')
            ->limit(self::LOOKUP_LIMIT)
            ->get()
            ->map(fn (BloodRequest $request): array => $this->summaryOf($request))
            ->all();
    }

    /**
     * The patient bills holding blood back, oldest first: the counter's queue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function queue(User $staff): array
    {
        return $this->counterBills($this->requireFacilityId($staff))
            ->whereHas('billing', fn (Builder $billing) => $billing->outstanding())
            ->orderBy('request_date')
            ->limit(self::QUEUE_LIMIT)
            ->get()
            ->map(fn (BloodRequest $request): array => $this->summaryOf($request))
            ->all();
    }

    /**
     * One patient bill as the counter takes it: who and what for, the lines, the balance, and how it can be paid.
     *
     * @return array<string, mixed>
     */
    public function bill(User $staff, int $requestId): array
    {
        $request = $this->counterBills($this->requireFacilityId($staff))
            ->whereKey($requestId)
            ->first()
            ?? throw $this->refuse(404, 'bill_not_found', 'No patient bill was found for that request at this centre.');

        /** @var Billing $billing */
        $billing = $request->billing;
        $open = PaymentAttempt::query()->where('billing_id', $billing->id)->open()->latest('id')->first();

        return [
            ...$this->summaryOf($request),
            'lines' => array_map(fn (array $line): array => [
                'component_name' => $line['component_name'],
                'quantity' => $line['quantity'],
                'unit_price' => Money::toDecimal($line['unit_price']),
                'line_total' => Money::toDecimal($line['line_total']),
            ], $this->figures->linesFor($request)),
            'billing' => $this->billingService->format($billing),
            'checkout' => $this->checkout->availability($billing, $staff->facility),
            'open_attempt' => $open ? $this->checkout->format($open) : null,
        ];
    }

    /**
     * This centre's Patient Transfusion requests that have a bill.
     */
    private function counterBills(int $facilityId): Builder
    {
        return BloodRequest::query()
            ->addressedTo($facilityId)
            ->where('request_purpose', RequestPurpose::PatientTransfusion->value)
            ->whereHas('billing')
            ->with([
                'billing',
                'requestingFacility:id,name',
                'transfusionRequest:id,reference_number',
                'walkIn:id,request_id,presented_reference',
                'items.component:id,name',
                'items.bloodType:id,code',
            ]);
    }

    /**
     * One bill as a search result or queue row.
     *
     * @return array<string, mixed>
     */
    private function summaryOf(BloodRequest $request): array
    {
        /** @var Billing $billing */
        $billing = $request->billing;
        $total = Money::toCentavos($billing->total_amount);
        $collected = $this->figures->collectedFor($billing);

        return [
            'request_id' => $request->id,
            'reference_number' => $request->reference_number,
            'transfusion_reference' => $request->transfusionRequest?->reference_number,
            'presented_reference' => $request->walkIn?->presented_reference,
            'is_walk_in' => $request->request_source->isWalkIn(),
            'patient_name' => $request->patientFullName(),
            'requesting_facility' => $request->requestingFacility?->name,
            'blood_types' => $request->bloodTypeCodes(),
            'is_emergency' => $request->urgency_level->isPrioritised(),
            'request_date' => $request->request_date?->toIso8601String(),
            'billing_status' => $billing->status->value,
            'billing_status_label' => $billing->status->label(),
            'total_amount' => Money::toDecimal($total),
            'collected' => Money::toDecimal($collected),
            'outstanding' => Money::toDecimal($this->billingService->outstandingFor($billing)),
            'clears_release' => $billing->clearsRelease(),
            'takes_payment' => $billing->status->isCollectible() && ! $billing->clearsRelease(),
        ];
    }

    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
