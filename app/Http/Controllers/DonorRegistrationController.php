<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterDonorRequest;
use App\Service\DonorService;
use Illuminate\Http\JsonResponse;

class DonorRegistrationController extends Controller
{
    public function __construct(
        private readonly DonorService $donorService
    ) {
    }

    public function register(RegisterDonorRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorService->register($request->validated()),
            201
        );
    }
}
