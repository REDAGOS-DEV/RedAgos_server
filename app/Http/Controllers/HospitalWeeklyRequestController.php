<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListWeeklyRequestsRequest;
use App\Http\Requests\StoreWeeklyRequestRequest;
use App\Service\WeeklyRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A hospital blood bank's weekly requests: its scheduled restocks, sent on its request days.
 */
class HospitalWeeklyRequestController extends Controller
{
    public function __construct(
        private readonly WeeklyRequestService $weeklyRequestService
    ) {}

    /**
     * List this blood bank's weekly requests, newest first.
     */
    public function index(ListWeeklyRequestsRequest $request): JsonResponse
    {
        return response()->json(
            $this->weeklyRequestService->list(
                $request->user(),
                $request->safe()->except('per_page'),
                $request->integer('per_page', 15)
            )
        );
    }

    /**
     * Send a centre today's weekly request.
     */
    public function store(StoreWeeklyRequestRequest $request): JsonResponse
    {
        return response()->json(
            $this->weeklyRequestService->submit($request->user(), $request->validated()),
            201
        );
    }

    /**
     * Show one weekly request, with every bag dispatched for it.
     */
    public function show(Request $request, int $weeklyRequest): JsonResponse
    {
        return response()->json(
            $this->weeklyRequestService->show($request->user(), $weeklyRequest)
        );
    }

    /**
     * Say where this blood bank stands against its request days today.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json(
            $this->weeklyRequestService->status($request->user())
        );
    }
}
