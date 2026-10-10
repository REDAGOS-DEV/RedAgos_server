<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentAttemptReasonRequest;
use App\Http\Requests\StartCheckoutRequest;
use App\Service\PaymentCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GCash checkouts billing staff open at the counter for a patient's watcher.
 */
class BloodCenterCheckoutController extends Controller
{
    public function __construct(
        private readonly PaymentCheckoutService $checkout
    ) {}

    /**
     * Open a checkout for one of this centre's statements, or return the one already open.
     */
    public function store(StartCheckoutRequest $request, int $bloodRequest): JsonResponse
    {
        $result = $this->checkout->start($request->user(), $bloodRequest, $request->validated()['payer_name']);

        return response()->json($result, $result['created'] ? 201 : 200);
    }

    /**
     * List every checkout opened against one of this centre's statements.
     */
    public function index(Request $request, int $bloodRequest): JsonResponse
    {
        return response()->json(['attempts' => $this->checkout->attempts($request->user(), $bloodRequest)]);
    }

    /**
     * Close an open checkout so the payment can be taken another way.
     */
    public function supersede(PaymentAttemptReasonRequest $request, int $bloodRequest, int $attempt): JsonResponse
    {
        return response()->json(
            $this->checkout->supersede($request->user(), $bloodRequest, $attempt, $request->validated()['reason'])
        );
    }

    /**
     * Ask the payment provider again about a checkout flagged for review.
     */
    public function reverify(Request $request, int $bloodRequest, int $attempt): JsonResponse
    {
        return response()->json($this->checkout->reverify($request->user(), $bloodRequest, $attempt));
    }

    /**
     * Close a checkout for good once the provider confirms nothing was paid on it.
     */
    public function close(PaymentAttemptReasonRequest $request, int $bloodRequest, int $attempt): JsonResponse
    {
        return response()->json(
            $this->checkout->close($request->user(), $bloodRequest, $attempt, $request->validated()['reason'])
        );
    }
}
