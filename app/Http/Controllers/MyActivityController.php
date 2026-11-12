<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListMyActivityRequest;
use App\Service\AuditLogService;
use Illuminate\Http\JsonResponse;

/**
 * My activity: what the signed-in user did, from the audit trail, and the
 * sign-ins refused on their account. Theirs alone, so no ability is needed.
 */
class MyActivityController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService
    ) {}

    public function index(ListMyActivityRequest $request): JsonResponse
    {
        return response()->json(
            $this->auditLogService->own($request->user(), $request->filters(), $request->integer('per_page', 25))
        );
    }
}
