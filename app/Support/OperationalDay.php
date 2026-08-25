<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The single answer to "what is today" for blood-unit expiry.
 *
 * Three places need that answer and must not disagree: the expiry sweep, the
 * expiry_date validation rules, and days_remaining in the inventory listing.
 * Resolving each of them through PHP's ambient timezone would let them drift —
 * config/app.php reads env('APP_TIMEZONE', 'UTC'), so a fresh clone and the
 * test suite both run in UTC while the deployment runs in Manila, and under UTC
 * Manila's 00:00-08:00 is still the previous date.
 *
 * Expiry is a date rather than an instant, which is why this returns a date
 * string as well as a moment: comparing a date column against a timestamp is
 * how the eight-hour disagreement gets in.
 */
final class OperationalDay
{
    /**
     * The current moment in the operational timezone.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /**
     * The current operational date as Y-m-d, for comparison against date columns.
     */
    public static function todayAsDate(): string
    {
        return self::today()->toDateString();
    }

    /**
     * Whole days from the operational today until a given expiry date.
     *
     * Negative for a date already past, zero for a unit expiring today — which
     * is still usable, since the sweep only expires expiry_date < today.
     */
    public static function daysUntil(CarbonImmutable $expiryDate): int
    {
        return self::today()->startOfDay()->diffInDays($expiryDate->startOfDay(), false);
    }

    /**
     * The configured operational timezone.
     */
    private static function timezone(): string
    {
        return (string) config('blood_center.timezone', 'Asia/Manila');
    }
}
