<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The link a browser uses to show a donor's profile photo.
 *
 * The file stays on the private `local` disk and its path never leaves the
 * server: every response that shows the photo carries a 30-minute signed link
 * to `donors.avatar.show` instead. Built here so the upload reply, the profile
 * and the current-user resource cannot drift apart in how they expose it.
 */
final class DonorAvatar
{
    private const DISK = 'local';

    private const LIFETIME_MINUTES = 30;

    /**
     * A short-lived signed path to the photo, or null when there is none.
     *
     * Null as well when the path is set but the file is gone, because the
     * route would 404 and the client would draw a broken image in place of
     * the donor's initials.
     */
    public static function urlFor(User $donor): ?string
    {
        $path = $donor->donorProfile?->profile_image_path;

        if ($path === null || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        // Relative, like the email-verification link: an absolute signature
        // also covers the host, and Laravel only sees the one the dev proxy
        // rewrites to (127.0.0.1:8000), which a phone on the LAN cannot reach.
        // The client resolves the path against its own API base instead.
        return URL::temporarySignedRoute(
            'donors.avatar.show',
            now()->addMinutes(self::LIFETIME_MINUTES),
            ['user' => $donor->uuid],
            absolute: false
        );
    }
}
