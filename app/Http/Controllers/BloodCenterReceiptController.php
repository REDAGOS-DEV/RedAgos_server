<?php

namespace App\Http\Controllers;

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
     * Show one receipt as its snapshot reads, for the counter to print or reprint.
     *
     * Without the payment reference, as every receipt projection is.
     */
    public function show(Request $request, int $receipt): JsonResponse
    {
        return response()->json([
            'receipt' => $this->receipts->format(
                $this->receipts->findIssuedBy($receipt, (int) $request->user()->facility_id)
            ),
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
