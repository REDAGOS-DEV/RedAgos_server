<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBillingProgramRequest;
use App\Http\Requests\UpdateBillingProgramRequest;
use App\Service\BillingProgramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterBillingProgramController extends Controller
{
    public function __construct(private readonly BillingProgramService $programs) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->programs->index($request->user()));
    }

    public function store(StoreBillingProgramRequest $request): JsonResponse
    {
        return response()->json($this->programs->create($request->user(), $request->validated()), 201);
    }

    public function update(UpdateBillingProgramRequest $request, int $program): JsonResponse
    {
        return response()->json($this->programs->update($request->user(), $program, $request->validated()));
    }
}
