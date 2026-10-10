<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListBillingTransactionsRequest;
use App\Service\BillingTransactionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The centre's billing journal: every money event, numbered and categorised.
 */
class BloodCenterBillingTransactionController extends Controller
{
    public function __construct(
        private readonly BillingTransactionService $transactions
    ) {}

    public function index(ListBillingTransactionsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return response()->json(
            $this->transactions->list($request->user(), $filters, (int) ($filters['per_page'] ?? 25))
        );
    }

    public function export(ListBillingTransactionsRequest $request): StreamedResponse
    {
        return $this->transactions->export($request->user(), $request->validated());
    }
}
