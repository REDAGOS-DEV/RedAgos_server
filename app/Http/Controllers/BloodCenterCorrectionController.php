<?php

namespace App\Http\Controllers;

use App\Enums\CorrectionSubject;
use App\Service\CorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BloodCenterCorrectionController extends Controller
{
    public function __construct(
        private readonly CorrectionService $correctionService
    ) {}

    /**
     * The caller's own requests (`scope=mine`) or those they may decide (`scope=review`).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'scope' => ['sometimes', 'string', Rule::in(['mine', 'review'])],
            'status' => ['sometimes', 'string', Rule::in(['pending', 'approved', 'rejected'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(
            $this->correctionService->list($request->user(), $filters, (int) ($filters['per_page'] ?? 25))
        );
    }

    /**
     * Ask for a saved record to be corrected. `changes` is validated against
     * the original write's own rules by the service.
     */
    public function store(Request $request, int $donation): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', Rule::in(CorrectionSubject::values())],
            'reason' => ['required', 'string', 'max:1000'],
            'changes' => ['required', 'array'],
        ], [
            'reason.required' => 'Say what was entered wrongly and why it is being corrected.',
        ]);

        return response()->json($this->correctionService->request(
            $request->user(),
            $donation,
            CorrectionSubject::from($validated['subject']),
            $validated['changes'],
            $validated['reason']
        ), 201);
    }

    public function approve(Request $request, int $correction): JsonResponse
    {
        $validated = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return response()->json(
            $this->correctionService->approve($request->user(), $correction, $validated['note'] ?? null)
        );
    }

    public function reject(Request $request, int $correction): JsonResponse
    {
        $validated = $request->validate(
            ['note' => ['required', 'string', 'max:1000']],
            ['note.required' => 'Say why the correction is rejected.']
        );

        return response()->json(
            $this->correctionService->reject($request->user(), $correction, $validated['note'])
        );
    }
}
