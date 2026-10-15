<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListReceiptsRequest;
use App\Service\PaymentReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Payment Acknowledgement Receipts issued by this centre.
 */
class BloodCenterReceiptController extends Controller
{
    public function __construct(
        private readonly PaymentReceiptService $receipts
    ) {}

    /**
     * The receipts this centre has issued, newest first.
     */
    public function index(ListReceiptsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return response()->json($this->receipts->list(
            (int) $request->user()->facility_id,
            $filters,
            (int) ($filters['per_page'] ?? 20)
        ));
    }

    /**
     * Show one receipt as its snapshot reads, for the counter to print or reprint.
     *
     * The projection leaves the payment reference and a card payment's
     * additional information out, because the hospital reads it too; billing
     * staff here may read both, as the payments list shows them, so they are
     * added beside it. For a payment split across methods, each part's are in
     * payment_parts.
     */
    public function show(Request $request, int $receipt): JsonResponse
    {
        $found = $this->receipts->findIssuedBy($receipt, (int) $request->user()->facility_id);

        return response()->json([
            'receipt' => $this->receipts->format($found),
            'payment_reference' => $found->snapshot['payment']['reference_number'] ?? null,
            'payment_additional_information' => $found->snapshot['payment']['additional_information'] ?? null,
            'payment_parts' => array_map(fn (array $tender): array => [
                'method_label' => $tender['method_label'] ?? null,
                'amount_paid' => $tender['amount_paid'] ?? null,
                'reference_number' => $tender['reference_number'] ?? null,
                'additional_information' => $tender['additional_information'] ?? null,
            ], $found->snapshot['tenders'] ?? []),
        ]);
    }

    /**
     * Download one receipt, rendered from its frozen snapshot.
     */
    public function pdf(Request $request, int $receipt): Response
    {
        return $this->receipts->pdf(
            $this->receipts->findIssuedBy($receipt, (int) $request->user()->facility_id),
            $request->user()
        );
    }
}
