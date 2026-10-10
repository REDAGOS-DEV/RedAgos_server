<?php

namespace App\Http\Controllers;

use App\Models\BloodRequest;
use App\Service\BillingService;
use App\Service\StatementRevisionService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Statements of Account issued by this centre: the frozen revisions of its statements.
 *
 * Scoped like everything else in billing: a request addressed to the caller's
 * own centre, a revision that centre issued.
 */
class BloodCenterStatementController extends Controller
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly StatementRevisionService $statements
    ) {}

    /**
     * List the statements issued for one of this centre's requests.
     */
    public function index(Request $request, int $bloodRequest): JsonResponse
    {
        $billing = BloodRequest::query()
            ->addressedTo((int) $request->user()->facility_id)
            ->whereKey($bloodRequest)
            ->first()?->billing()->first()
            ?? throw new HttpResponseException(response()->json([
                'message' => 'No statement has been raised for this request yet.',
                'code' => 'billing_missing',
            ], 404));

        return response()->json(['statements' => $this->statements->listFor($billing)]);
    }

    /**
     * Issue a Statement of Account, or show the current one again if nothing has changed.
     */
    public function store(Request $request, int $bloodRequest): JsonResponse
    {
        $result = $this->billingService->issueStatement($request->user(), $bloodRequest);

        return response()->json($result, $result['created'] ? 201 : 200);
    }

    /**
     * Download one issued statement as its printed Statement of Account.
     */
    public function pdf(Request $request, int $revision): Response
    {
        return $this->statements->pdf(
            $this->statements->findIssuedBy($revision, (int) $request->user()->facility_id),
            $request->user()
        );
    }
}
