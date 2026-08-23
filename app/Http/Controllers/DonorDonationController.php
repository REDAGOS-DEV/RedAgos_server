<?php

namespace App\Http\Controllers;

use App\Service\DonationHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DonorDonationController extends Controller
{
    public function __construct(
        private readonly DonationHistoryService $donationHistoryService
    ) {}

    /**
     * List the authenticated donor's donation history.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'in:registered,screening,collected,tested,completed,rejected'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->donationHistoryService->list($request->user(), $filters));
    }
}
