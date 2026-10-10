<?php

namespace App\Http\Controllers;

use App\Models\BloodRequest;
use App\Models\PaymentReceipt;
use App\Service\BillingService;
use App\Service\PaymentReceiptService;
use App\Service\StatementRevisionService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The requesting hospital's read-only view of what its requests were billed.
 *
 * Read only, by owner decision: the patient or watcher pays at the blood
 * centre, and a weekly order is billed to the hospital by statement only.
 * Scoped to requests this hospital raised; payment references are not shown.
 */
class HospitalBillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly StatementRevisionService $statements,
        private readonly PaymentReceiptService $receipts
    ) {}

    /**
     * Show the statement for one of this hospital's requests, its issued statements and its receipts.
     */
    public function show(Request $request, int $bloodRequest): JsonResponse
    {
        $raised = BloodRequest::query()
            ->raisedBy((int) $request->user()->facility_id)
            ->whereKey($bloodRequest)
            ->first()
            ?? throw $this->refuse(404, 'request_not_found', 'Blood request not found.');

        $billing = $raised->billing()->first()
            ?? throw $this->refuse(404, 'billing_missing', 'No statement has been raised for this request yet.');

        $receipts = PaymentReceipt::query()
            ->whereHas('payment', fn ($query) => $query->where('billing_id', $billing->id))
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentReceipt $receipt): array => $this->receipts->format($receipt))
            ->all();

        return response()->json([
            'billing' => $this->billingService->format($billing),
            'statements' => $this->statements->listFor($billing),
            'receipts' => $receipts,
        ]);
    }

    /**
     * Download an issued statement addressed to this hospital.
     */
    public function statementPdf(Request $request, int $revision): Response
    {
        return $this->statements->pdf(
            $this->statements->findAddressedTo($revision, (int) $request->user()->facility_id),
            $request->user()
        );
    }

    /**
     * Download a receipt for one of this hospital's requests.
     */
    public function receiptPdf(Request $request, int $receipt): Response
    {
        return $this->receipts->pdf(
            $this->receipts->findForRequester($receipt, (int) $request->user()->facility_id),
            $request->user()
        );
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
