<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBookingScheduleRequest;
use App\Service\BookingScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The centre's booking days, hours and places (BookingScheduleService).
 */
class BloodCenterBookingScheduleController extends Controller
{
    public function __construct(
        private readonly BookingScheduleService $bookingScheduleService
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->bookingScheduleService->show($request->user())]);
    }

    public function update(UpdateBookingScheduleRequest $request): JsonResponse
    {
        return response()->json($this->bookingScheduleService->update($request->user(), $request->validated()));
    }
}
