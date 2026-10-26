<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListAuditLogRequest;
use App\Service\AuditLogService;
use Illuminate\Http\JsonResponse;

/**
 * The Audit Log: the Blood Center Admin's read of what the centre's staff did.
 */
class BloodCenterAuditLogController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService
    ) {}

    public function index(ListAuditLogRequest $request): JsonResponse
    {
        return response()->json(
            $this->auditLogService->list($request->user(), $request->filters(), $request->integer('per_page', 25))
        );
    }
}
