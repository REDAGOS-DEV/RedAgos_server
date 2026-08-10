<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAppointmentRequest;
use App\Models\DonationAppointment;
use App\Service\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DonorAppointmentController extends Controller
{
    public function __construct(
        private readonly AppointmentService $appointmentService
    ) {}

    /**
     * List the authenticated donor's own appointments.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->appointmentService->listForDonor($request->user())
        );
    }

    /**
     * Book an appointment for the authenticated donor.
     */
    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        return response()->json(
            $this->appointmentService->book($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Move one of the donor's own appointments to a new slot.
     */
    public function update(StoreAppointmentRequest $request, DonationAppointment $appointment): JsonResponse
    {
        Gate::authorize('update', $appointment);

        return response()->json(
            $this->appointmentService->reschedule($request->user(), $appointment, $request->validated())
        );
    }

    /**
     * Cancel one of the donor's own appointments.
     */
    public function destroy(DonationAppointment $appointment): JsonResponse
    {
        Gate::authorize('delete', $appointment);

        return response()->json($this->appointmentService->cancel($appointment));
    }
}
