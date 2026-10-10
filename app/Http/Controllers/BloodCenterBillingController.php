<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplySubsidyRequest;
use App\Http\Requests\ListBillingsRequest;
use App\Http\Requests\RecordPaymentRequest;
use App\Models\Billing;
use App\Models\BloodRequest;
use App\Models\PaymentAttempt;
use App\Service\BillingService;
use App\Service\PaymentCheckoutService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Statements raised against blood requests, and the payments settling them.
 *
 * Every lookup is scoped through the request's target facility: a statement
 * belongs to the centre that raised it, and billing staff at one centre have no
 * business reading another's.
 */
class BloodCenterBillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly PaymentCheckoutService $checkout
    ) {}

    /**
     * List the statements raised against this facility's incoming requests.
     */
    public function index(ListBillingsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return response()->json(
            $this->billingService->list(
                (int) $request->user()->facility_id,
                $filters,
                (int) ($filters['per_page'] ?? 15)
            )
        );
    }

    /**
     * Show the statement for one of this facility's incoming requests.
     *
     * With whether a GCash checkout could be opened for it now, and the one
     * that is open, if any — so the screen can offer the right action.
     */
    public function show(Request $request, int $bloodRequest): JsonResponse
    {
        $billing = $this->billingForFacility($bloodRequest, $request->user()->facility_id);
        $open = PaymentAttempt::query()->where('billing_id', $billing->id)->open()->latest('id')->first();

        return response()->json([
            'billing' => $this->billingService->format($billing),
            'checkout' => $this->checkout->availability($billing, $request->user()->facility),
            'open_attempt' => $open ? $this->checkout->format($open) : null,
        ]);
    }

    /**
     * List the payments recorded against one statement, as the caller may act on them.
     */
    public function payments(Request $request, int $bloodRequest): JsonResponse
    {
        $billing = $this->billingForFacility($bloodRequest, $request->user()->facility_id);

        return response()->json(['payments' => $this->billingService->payments($billing, $request->user())]);
    }

    /**
     * Record a settlement against a statement.
     */
    public function storePayment(RecordPaymentRequest $request, int $bloodRequest): JsonResponse
    {
        $billing = $this->billingForFacility($bloodRequest, $request->user()->facility_id);

        return response()->json(
            $this->billingService->recordPayment($request->user(), $billing, $request->validated()),
            201
        );
    }

    /**
     * Meet a statement from the government subsidy instead of charging for it.
     */
    public function storeSubsidy(ApplySubsidyRequest $request, int $bloodRequest): JsonResponse
    {
        $billing = $this->billingForFacility($bloodRequest, $request->user()->facility_id);

        return response()->json(
            $this->billingService->applySubsidy(
                $request->user(),
                $billing,
                $request->validated()['reason'] ?? null
            )
        );
    }

    /**
     * Resolve a statement that belongs to this facility, or refuse.
     */
    private function billingForFacility(int $requestId, ?int $facilityId): Billing
    {
        $bloodRequest = BloodRequest::query()
            ->addressedTo((int) $facilityId)
            ->whereKey($requestId)
            ->first();

        if (! $bloodRequest) {
            throw $this->refuse(404, 'request_not_found', 'Blood request not found.');
        }

        return $bloodRequest->billing()->first()
            ?? throw $this->refuse(
                404,
                'billing_missing',
                'No statement has been raised for this request yet.'
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
