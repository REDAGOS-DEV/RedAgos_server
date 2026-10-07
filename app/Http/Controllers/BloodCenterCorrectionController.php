<?php

namespace App\Http\Controllers;

use App\Enums\CorrectionSubject;
use App\Enums\CorrectionTarget;
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
            // Only the donation's own subjects: this route's key is a donation
            // id, so a unit or payment subject sent here would be read as one.
            'subject' => ['required', 'string', Rule::in(CorrectionSubject::valuesFor(CorrectionTarget::Donation))],
            ...$this->fillingRules(),
        ], $this->fillingMessages());

        return response()->json($this->correctionService->request(
            $request->user(),
            $donation,
            CorrectionSubject::from($validated['subject']),
            $validated['changes'],
            $validated['reason']
        ), 201);
    }

    /**
     * Ask for a unit's storage location or expiry date to be corrected.
     */
    public function storeForUnit(Request $request, string $unit): JsonResponse
    {
        return $this->fileFor($request, $unit, CorrectionSubject::UnitDetails);
    }

    /**
     * Ask for a dispatched unit's release time or recipient to be corrected.
     */
    public function storeForAllocation(Request $request, int $allocation): JsonResponse
    {
        return $this->fileFor($request, $allocation, CorrectionSubject::Dispatch);
    }

    /**
     * Ask for a recorded payment to be corrected.
     */
    public function storeForPayment(Request $request, int $payment): JsonResponse
    {
        return $this->fileFor($request, $payment, CorrectionSubject::Payment);
    }

    /**
     * File a correction whose subject the route itself implies.
     *
     * Validation runs before the service so a malformed body is a 422 for
     * everyone; whether the caller may file this subject is then the
     * service's first question.
     */
    private function fileFor(Request $request, int|string $targetId, CorrectionSubject $subject): JsonResponse
    {
        $validated = $request->validate($this->fillingRules(), $this->fillingMessages());

        return response()->json($this->correctionService->request(
            $request->user(),
            $targetId,
            $subject,
            $validated['changes'],
            $validated['reason']
        ), 201);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function fillingRules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'changes' => ['required', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function fillingMessages(): array
    {
        return ['reason.required' => 'Say what was entered wrongly and why it is being corrected.'];
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
