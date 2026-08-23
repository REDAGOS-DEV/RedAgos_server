<?php

namespace App\Http\Controllers;

use App\Service\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Donor-facing, read-only views over blood centre data. Administration of
 * facilities and drives belongs to the Blood Center module and is not exposed
 * here.
 */
class BookingCatalogController extends Controller
{
    public function __construct(
        private readonly AppointmentService $appointmentService
    ) {}

    /**
     * List blood centres currently accepting donations.
     */
    public function bloodCenters(): JsonResponse
    {
        return response()->json($this->appointmentService->bloodCenters());
    }

    /**
     * List upcoming mobile blood drives.
     */
    public function bloodDrives(): JsonResponse
    {
        return response()->json($this->appointmentService->bloodDrives());
    }

    /**
     * List bookable time slots for a centre on a given date.
     */
    public function timeSlots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'center_id' => ['required', 'integer', 'exists:facilities,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json(
            $this->appointmentService->timeSlots(
                (int) $validated['center_id'],
                $validated['date']
            )
        );
    }
}
