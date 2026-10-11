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
     * The projection leaves the payment reference out, because the hospital
     * reads it too; billing staff here may read references, as the payments
     * list shows them, so it is added beside it.
     */
    public function show(Request $request, int $receipt): JsonResponse
    {
        $found = $this->receipts->findIssuedBy($receipt, (int) $request->user()->facility_id);

        return response()->json([
            'receipt' => $this->receipts->format($found),
            'payment_reference' => $found->snapshot['payment']['reference_number'] ?? null,
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
