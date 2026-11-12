<?php

namespace App\Http\Controllers;

use App\Http\Requests\MatchDrivePreRegistrationRequest;
use App\Http\Requests\StoreDriveWalkInRequest;
use App\Service\DriveCheckInService;
use App\Service\DrivePreRegistrationService;
use App\Service\DriveRosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloodCenterDrivePreRegistrationController extends Controller
{
    public function __construct(
        private readonly DrivePreRegistrationService $preRegistrationService,
        private readonly DriveRosterService $rosterService,
        private readonly DriveCheckInService $checkInService
    ) {}

    /**
     * A drive's roster: its list, its bookings and its walk-ins, with figures.
     */
    public function index(Request $request, int $drive): JsonResponse
    {
        return response()->json($this->rosterService->roster($request->user(), $drive));
    }

    /**
     * Take a person off the drive's list.
     */
    public function withdraw(Request $request, int $drive, int $registration): JsonResponse
    {
        return response()->json($this->preRegistrationService->withdraw($request->user(), $drive, $registration));
    }

    /**
     * Confirm that an entry is an existing donor.
     */
    public function match(MatchDrivePreRegistrationRequest $request, int $drive, int $registration): JsonResponse
    {
        return response()->json(
            $this->preRegistrationService->match($request->user(), $drive, $registration, $request->validated('donor_uuid'))
        );
    }

    /**
     * Undo a match made in error.
     */
    public function unmatch(Request $request, int $drive, int $registration): JsonResponse
    {
        return response()->json($this->preRegistrationService->unmatch($request->user(), $drive, $registration));
    }

    /**
     * Check in someone from the drive's list, on the drive's day.
     */
    public function checkIn(Request $request, int $drive, int $registration): JsonResponse
    {
        return response()->json($this->checkInService->checkInEntry($request->user(), $drive, $registration));
    }

    /**
     * Check in someone who came to the drive without registering.
     */
    public function walkIn(StoreDriveWalkInRequest $request, int $drive): JsonResponse
    {
        return response()->json(
            $this->checkInService->walkIn($request->user(), $drive, $request->validated('donor_uuid')),
            201
        );
    }
}
