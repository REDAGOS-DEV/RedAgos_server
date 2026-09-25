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
     * The first and last instant of an operational day.
     *
     * For comparing a TIMESTAMP column against a day. `whereDate()` asks the
     * database to take the date of a stored instant, which it does in the
     * connection's own reckoning — so a date computed here in Manila and a
     * timestamp written by now() in UTC disagree for the eight hours this
     * class exists to warn about. Two absolute instants have no such
     * ambiguity, whatever APP_TIMEZONE happens to be.
     *
     * Date columns are different and are right to use whereDate(): a date has
     * no instant to convert.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function boundsFor(?string $date = null): array
    {
        $day = $date === null
            ? self::today()
            : CarbonImmutable::parse($date, self::timezone());

        // Handed back in the application's own timezone. The instants are the
        // same either way, but the query binds them as wall-clock strings and
        // the column stores wall clock in APP_TIMEZONE — so a Manila-shaped
        // bound would be compared against a UTC-shaped column and miss by the
        // very eight hours this is meant to close.
        $appTimezone = (string) config('app.timezone', 'UTC');

        return [
            $day->startOfDay()->setTimezone($appTimezone),
            $day->endOfDay()->setTimezone($appTimezone),
        ];
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
