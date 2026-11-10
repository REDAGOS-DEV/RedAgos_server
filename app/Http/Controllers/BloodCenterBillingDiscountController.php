<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBillingDiscountRequest;
use App\Http\Requests\UpdateBillingDiscountRequest;
use App\Service\BillingDiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Billing Supervisor's list of the centre's discounts.
 */
class BloodCenterBillingDiscountController extends Controller
{
    public function __construct(private readonly BillingDiscountService $discounts) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->discounts->index($request->user()));
    }

    public function store(StoreBillingDiscountRequest $request): JsonResponse
    {
        return response()->json($this->discounts->create($request->user(), $request->validated()), 201);
    }

    public function update(UpdateBillingDiscountRequest $request, int $discount): JsonResponse
    {
        return response()->json($this->discounts->update($request->user(), $discount, $request->validated()));
    }
}
