<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckDonorRegistrationStepRequest;
use App\Http\Requests\RegisterDonorRequest;
use App\Service\DonorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DonorRegistrationController extends Controller
{
    public function __construct(
        private readonly DonorService $donorService
    ) {}

    public function register(RegisterDonorRequest $request): JsonResponse
    {
        return response()->json(
            $this->donorService->register($request->validated()),
            201
        );
    }

    /**
     * One step of the registration page, checked before Continue: the request
     * applies the step's rules, then the address and phone are looked up as
     * register() looks them up. Stores nothing.
     */
    public function checkStep(CheckDonorRegistrationStepRequest $request): Response
    {
        $this->donorService->assertRegistrationAvailable($request->safe()->except('step'));

        return response()->noContent();
    }
}
