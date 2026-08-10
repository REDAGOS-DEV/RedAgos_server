<?php

namespace App\Http\Controllers;

use App\Service\DonorNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DonorNotificationController extends Controller
{
    public function __construct(
        private readonly DonorNotificationService $notificationService
    ) {}

    /**
     * List the authenticated donor's notifications.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['sometimes', 'nullable', Rule::in(DonorNotificationService::CATEGORIES)],
            'read' => ['sometimes', 'nullable', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->notificationService->list($request->user(), $filters));
    }

    /**
     * Return the donor's unread notification count.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json($this->notificationService->unreadCount($request->user()));
    }

    /**
     * Mark one of the donor's own notifications as read.
     */
    public function update(Request $request, string $notification): JsonResponse
    {
        return response()->json(
            $this->notificationService->markAsRead($request->user(), $notification)
        );
    }

    /**
     * Mark every unread notification for the donor as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        return response()->json($this->notificationService->markAllAsRead($request->user()));
    }
}
