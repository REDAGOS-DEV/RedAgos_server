<?php

namespace App\Http\Controllers;

use App\Support\StaffNotificationTopics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A blood-centre staff member's notices: which they get and why, and the few
 * they may turn off (StaffNotificationTopics).
 */
class StaffNotificationPreferencesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'topics' => StaffNotificationTopics::for($request->user()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        // Only the optional switches; the always-on notices have none to set.
        $validated = $request->validate(array_map(
            fn (): array => ['sometimes', 'boolean'],
            StaffNotificationTopics::OPTIONAL,
        ));

        $user = $request->user();
        $user->forceFill([
            'notification_preferences' => [
                ...StaffNotificationTopics::preferences($user),
                ...array_map('boolval', $validated),
            ],
        ])->save();

        return response()->json([
            'message' => 'Notification preferences updated.',
            'topics' => StaffNotificationTopics::for($user),
        ]);
    }
}
