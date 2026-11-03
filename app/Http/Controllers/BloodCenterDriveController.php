<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelMobileEventRequest;
use App\Http\Requests\StoreMobileEventRequest;
use App\Http\Requests\UpdateMobileEventRequest;
use App\Service\MobileEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterDriveController extends Controller
{
    public function __construct(
        private readonly MobileEventService $mobileEventService
    ) {}

    /**
     * The drives this facility has scheduled, with headline figures.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->mobileEventService->list($request->user())
        );
    }

    /**
     * Schedule a new mobile drive.
     */
    public function store(StoreMobileEventRequest $request): JsonResponse
    {
        return response()->json(
            $this->mobileEventService->create($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Change a drive's details.
     */
    public function update(UpdateMobileEventRequest $request, int $drive): JsonResponse
    {
        return response()->json(
            $this->mobileEventService->update($request->user(), $drive, $request->validated())
        );
    }

    /**
     * Show a draft drive to donors.
     */
    public function publish(Request $request, int $drive): JsonResponse
    {
        return response()->json(
            $this->mobileEventService->publish($request->user(), $drive)
        );
    }

    /**
     * Stop new bookings for a published drive.
     */
    public function closeRegistration(Request $request, int $drive): JsonResponse
    {
        return response()->json(
            $this->mobileEventService->setRegistrationOpen($request->user(), $drive, false)
        );
    }

    /**
     * Resume new bookings for a published drive.
     */
    public function reopenRegistration(Request $request, int $drive): JsonResponse
    {
        return response()->json(
            $this->mobileEventService->setRegistrationOpen($request->user(), $drive, true)
        );
    }

    /**
     * Cancel a drive and the bookings waiting on it.
     */
    public function cancel(CancelMobileEventRequest $request, int $drive): JsonResponse
    {
        return response()->json(
            $this->mobileEventService->cancel($request->user(), $drive, $request->validated('reason'))
        );
    }
}
