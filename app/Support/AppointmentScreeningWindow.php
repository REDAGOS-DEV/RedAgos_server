<?php

namespace App\Support;

use App\Models\DonationAppointment;
use Carbon\CarbonImmutable;

/**
 * When a donor may answer the health questionnaire for a booked appointment.
 *
 * A donor books freely and answers the questionnaire the day before, so that
 * what the blood centre reads at the counter describes the donor as they are
 * now rather than as they were up to 90 days ago.
 *
 *     opens    the day before the appointment, 00:00:00
 *     closes   the day of the appointment,     23:59:59
 *
 * The close is end-of-day rather than the appointment time on purpose. A donor
 * who never answered has no QR, so the counter falls back to an ID lookup and
 * the donor answers on their phone there and then -- and a window that shut at
 * the booked time would be shut for exactly the person who needs it. It also
 * gives donors a rule they can state without looking anything up: the day
 * before, or the day of.
 *
 * Three callers must agree on this -- the submission guard, the reschedule
 * check and the reminder command -- which is the same threshold OperationalDay
 * uses to justify itself, and this class is built on it for the same reason.
 * config/app.php reads env('APP_TIMEZONE', 'UTC'), so a fresh clone and the
 * test suite run in UTC while the deployment runs in Manila. Computing these
 * bounds from a bare now() would open the window eight hours late for every
 * Manila donor and still pass a UTC test suite.
 */
final class AppointmentScreeningWindow
{
    /**
     * The moment the donor may first answer for this appointment.
     */
    public static function opensAt(DonationAppointment $appointment): CarbonImmutable
    {
        return self::appointmentDay($appointment)
            ->subDays(self::windowDays())
            ->startOfDay();
    }

    /**
     * The moment the window shuts.
     */
    public static function closesAt(DonationAppointment $appointment): CarbonImmutable
    {
        return self::appointmentDay($appointment)->endOfDay();
    }

    /**
     * Determine whether a moment falls inside the window.
     */
    public static function contains(DonationAppointment $appointment, ?CarbonImmutable $moment = null): bool
    {
        $moment ??= OperationalDay::today();

        return $moment->betweenIncluded(
            self::opensAt($appointment),
            self::closesAt($appointment)
        );
    }

    /**
     * Determine whether the window has not opened yet.
     */
    public static function isTooEarly(DonationAppointment $appointment, ?CarbonImmutable $moment = null): bool
    {
        $moment ??= OperationalDay::today();

        return $moment->lessThan(self::opensAt($appointment));
    }

    /**
     * The date the window opens, as Y-m-d, for a message the donor can act on.
     */
    public static function opensOn(DonationAppointment $appointment): string
    {
        return self::opensAt($appointment)->toDateString();
    }

    /**
     * The appointment's calendar day in the operational timezone.
     *
     * appointment_datetime is stored as a timestamp, so it is read back in the
     * app timezone; shifting it to the operational one before taking the date
     * is what keeps "the day before" meaning the day the donor would call it.
     */
    private static function appointmentDay(DonationAppointment $appointment): CarbonImmutable
    {
        return CarbonImmutable::parse($appointment->appointment_datetime)
            ->setTimezone(OperationalDay::today()->timezone);
    }

    private static function windowDays(): int
    {
        return (int) config('donation.appointment_screening_window_days', 1);
    }
}
