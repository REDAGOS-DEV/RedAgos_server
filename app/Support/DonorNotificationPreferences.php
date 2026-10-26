<?php

namespace App\Support;

use App\Models\User;

/**
 * What a donor chose to be told, and on which channel.
 *
 * The donor's Settings screen is a grid of topics against two channels, in-app
 * and email. Some cells are switches, stored here; the rest are fixed and never
 * stored:
 *
 *   - In-app copies of appointments and donation results are always written.
 *     The notifications screen is the record of them, and a donor must not
 *     miss a deferral by having switched it off.
 *   - "Please contact your blood centre" (DonorContactRequested) is in-app
 *     only, by the project owner's decision, and cannot be turned off.
 *   - Account and security mail (ID decisions, password resets) always goes.
 *
 * Every notification a donor can switch off asks this class before it picks
 * its channels, so the switches on screen are the ones the server keeps.
 */
final class DonorNotificationPreferences
{
    /** Email for booking confirmations and the day-before "questions open" notice. */
    public const APPOINTMENTS_EMAIL = 'appointments_email';

    /** Email for a donation recorded or a deferral. */
    public const RESULTS_EMAIL = 'results_email';

    /** Email from a blood centre that the donor can donate again (DonorOutreachService). */
    public const ELIGIBILITY_RENEWAL = 'eligibility_renewal';

    /** In-app notice when a blood centre the donor gave at schedules a drive. */
    public const DRIVES_IN_APP = 'drives_in_app';

    /** Email for the same drive notice. */
    public const DRIVES_EMAIL = 'drives_email';

    public const DEFAULTS = [
        self::APPOINTMENTS_EMAIL => true,
        self::RESULTS_EMAIL => true,
        self::ELIGIBILITY_RENEWAL => true,
        self::DRIVES_IN_APP => true,
        self::DRIVES_EMAIL => false,
    ];

    /**
     * Keys an earlier Settings screen saved, read when the new key is absent so
     * a donor's old choice carries over. That screen's "Email updates" switch
     * had no notification behind it and is dropped.
     */
    private const LEGACY = [
        self::APPOINTMENTS_EMAIL => 'appointment_reminders',
        self::RESULTS_EMAIL => 'donation_updates',
        self::DRIVES_IN_APP => 'blood_drive_announcements',
        self::DRIVES_EMAIL => 'nearby_drives',
    ];

    /**
     * The full set of switches from what is stored: the stored value, else the
     * legacy one, else the default.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, bool>
     */
    public static function resolve(?array $stored): array
    {
        $stored ??= [];
        $resolved = [];

        foreach (self::DEFAULTS as $key => $default) {
            $legacy = self::LEGACY[$key] ?? null;

            $resolved[$key] = match (true) {
                array_key_exists($key, $stored) => (bool) $stored[$key],
                $legacy !== null && array_key_exists($legacy, $stored) => (bool) $stored[$legacy],
                default => $default,
            };
        }

        return $resolved;
    }

    /**
     * @return array<string, bool>
     */
    public static function for(User $donor): array
    {
        return self::resolve($donor->donorProfile?->notification_preferences);
    }

    public static function allows(User $donor, string $key): bool
    {
        return self::for($donor)[$key] ?? true;
    }

    /**
     * Channels for a notice whose in-app copy is always written and whose
     * email follows `$emailKey`. No email for a donor without an address (a
     * counter registration may have none).
     *
     * @return array<int, string>
     */
    public static function alwaysInAppChannels(object $notifiable, string $emailKey): array
    {
        return self::wantsEmail($notifiable, $emailKey) ? ['mail', 'database'] : ['database'];
    }

    /**
     * Channels for a notice where both copies are the donor's choice; empty
     * when they turned both off.
     *
     * @return array<int, string>
     */
    public static function optionalChannels(object $notifiable, string $inAppKey, string $emailKey): array
    {
        $channels = [];

        if (self::wantsEmail($notifiable, $emailKey)) {
            $channels[] = 'mail';
        }

        if (! $notifiable instanceof User || self::allows($notifiable, $inAppKey)) {
            $channels[] = 'database';
        }

        return $channels;
    }

    private static function wantsEmail(object $notifiable, string $key): bool
    {
        if (! $notifiable instanceof User || ! $notifiable->hasEmailAddress()) {
            return false;
        }

        return self::allows($notifiable, $key);
    }
}
