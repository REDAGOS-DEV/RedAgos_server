<?php

namespace App\Http\Controllers;

use App\Service\DonorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DonorDashboardController extends Controller
{
    public function __construct(
        private readonly DonorService $donorService
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(
            $this->donorService->dashboard($request->user())
        );
    }
}
