<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListStaffAppointmentsRequest;
use App\Http\Requests\StoreStaffAppointmentRequest;
use App\Service\BloodCenterAppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Appointments page: the centre's bookings, and the desk booking a donor
 * who phones in. Check-in and no-show stay on BloodCenterCollectionController.
 */
class BloodCenterAppointmentController extends Controller
{
    public function __construct(
        private readonly BloodCenterAppointmentService $appointmentService
    ) {}

    public function index(ListStaffAppointmentsRequest $request): JsonResponse
    {
        return response()->json(
            $this->appointmentService->list($request->user(), $request->filters(), $request->integer('per_page', 20))
        );
    }

    public function export(ListStaffAppointmentsRequest $request): StreamedResponse
    {
        return $this->appointmentService->csv($request->user(), $request->filters());
    }

    /**
     * The slots on a date, at the centre or a drive, and the centre's drives.
     */
    public function options(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'drive_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        return response()->json($this->appointmentService->options(
            $request->user(),
            $request->input('date'),
            $request->filled('drive_id') ? $request->integer('drive_id') : null
        ));
    }

    public function store(StoreStaffAppointmentRequest $request): JsonResponse
    {
        return response()->json($this->appointmentService->book($request->user(), $request->validated()), 201);
    }

    /**
     * The ✓ on the list: mark the booking collected.
     */
    public function collected(Request $request, int $appointment): JsonResponse
    {
        return response()->json($this->appointmentService->markCollected($request->user(), $appointment));
    }

    public function cancel(Request $request, int $appointment): JsonResponse
    {
        return response()->json($this->appointmentService->cancel($request->user(), $appointment));
    }

    public function destroy(Request $request, int $appointment): JsonResponse
    {
        return response()->json($this->appointmentService->delete($request->user(), $appointment));
    }
}
