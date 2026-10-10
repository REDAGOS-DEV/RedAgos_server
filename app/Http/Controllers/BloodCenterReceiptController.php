<?php

namespace App\Http\Controllers;

use App\Service\PaymentReceiptService;
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
