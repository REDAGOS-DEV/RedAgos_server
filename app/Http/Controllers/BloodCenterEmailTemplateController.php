<?php

namespace App\Http\Controllers;

use App\Enums\DonorEmailTemplate;
use App\Http\Requests\UpdateEmailTemplateRequest;
use App\Service\DonorOutreachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The wording of the emails a centre sends its donors, kept in Settings.
 */
class BloodCenterEmailTemplateController extends Controller
{
    public function __construct(
        private readonly DonorOutreachService $donorOutreachService
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->donorOutreachService->templates($request->user()));
    }

    public function update(UpdateEmailTemplateRequest $request, DonorEmailTemplate $template): JsonResponse
    {
        return response()->json(
            $this->donorOutreachService->saveTemplate($request->user(), $template, $request->validated())
        );
    }

    /**
     * Go back to the built-in wording.
     */
    public function destroy(Request $request, DonorEmailTemplate $template): JsonResponse
    {
        return response()->json($this->donorOutreachService->resetTemplate($request->user(), $template));
    }
}
