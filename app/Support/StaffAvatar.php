<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * The link a browser uses to show a staff member's profile photo.
 *
 * DonorAvatar's twin for staff accounts, whose photo is on the user row rather
 * than a donor profile. The file stays on the private uploads disk and its
 * path never leaves the server: every response that shows the photo carries a
 * 30-minute signed link to `staff.avatar.show` instead.
 */
final class StaffAvatar
{
    private const LIFETIME_MINUTES = 30;

    /**
     * A short-lived signed path to the photo, or null when there is none.
     *
     * Null as well when the path is set but the file is gone, so the client
     * draws the initials rather than a broken image.
     */
    public static function urlFor(User $staff): ?string
    {
        $path = $staff->profile_image_path;

        if ($path === null || ! Uploads::disk()->exists($path)) {
            return null;
        }

        // Relative, for the same reason as DonorAvatar: the client resolves
        // the path against its own API base, whatever host signed it.
        return URL::temporarySignedRoute(
            'staff.avatar.show',
            now()->addMinutes(self::LIFETIME_MINUTES),
            ['user' => $staff->uuid],
            absolute: false
        );
    }
}
