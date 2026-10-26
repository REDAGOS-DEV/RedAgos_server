<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowAccountStatementRequest;
use App\Service\AccountStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A hospital's Statement of Account for a period: its invoices, its payments and its balance.
 */
class BloodCenterAccountStatementController extends Controller
{
    public function __construct(
        private readonly AccountStatementService $statements
    ) {}

    /**
     * The hospitals a statement can be drawn for.
     */
    public function hospitals(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->statements->hospitals($request->user())]);
    }

    public function show(ShowAccountStatementRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return response()->json(
            $this->statements->build($request->user(), (int) $filters['hospital_id'], $filters['from'], $filters['to'])
        );
    }

    public function pdf(ShowAccountStatementRequest $request): Response
    {
        $filters = $request->validated();

        return $this->statements->pdf($request->user(), (int) $filters['hospital_id'], $filters['from'], $filters['to']);
    }
}
