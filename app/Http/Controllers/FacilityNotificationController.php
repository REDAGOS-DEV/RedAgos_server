<?php

namespace App\Http\Controllers;

use App\Service\DonorNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The in-app notification inbox for facility staff, both sides of the exchange.
 *
 * Deliberately backed by DonorNotificationService rather than a second copy of
 * it. Laravel's notifications table is polymorphic and every method there is
 * already scoped to `$user->notifications()`, so the logic is identical for a
 * blood-bank requester, a blood-centre reviewer and a donor — only the route
 * and the role guarding it differ. The service name is now narrower than what
 * it does; renaming it would touch the donor routes for no behavioural gain, so
 * that is left for whenever those are next edited.
 */
class FacilityNotificationController extends Controller
{
    public function __construct(
        private readonly DonorNotificationService $notificationService
    ) {}

    /**
     * List the caller's notifications, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->notificationService->list($request->user(), $request->only(['category', 'read', 'per_page']))
        );
    }

    /**
     * Count the caller's unread notifications.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json($this->notificationService->unreadCount($request->user()));
    }

    /**
     * Mark one of the caller's notifications as read.
     */
    public function update(Request $request, string $notification): JsonResponse
    {
        return response()->json(
            $this->notificationService->markAsRead($request->user(), $notification)
        );
    }

    /**
     * Mark every unread notification for the caller as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        return response()->json($this->notificationService->markAllAsRead($request->user()));
    }
}
