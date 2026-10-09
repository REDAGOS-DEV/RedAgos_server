<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The command existing is not the command running. Without this registration
// past-expiry units keep reporting as available and the API is confidently
// wrong about issuable stock, so ScheduleRegistrationTest fails the build if it
// is ever removed while the command stays.
//
// 00:30 rather than midnight: far enough past the date boundary that a unit
// expiring "today" has had its whole day, and off the hour every other cron on
// a shared box fires at. The timezone is set per-entry from the same config the
// command computes its date from, rather than by changing APP_TIMEZONE globally.
Schedule::command('inventory:expire-units')
    ->dailyAt('00:30')
    ->timezone(config('blood_center.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// Same reasoning as above, and the same failure mode if it is ever dropped.
// Booking an appointment no longer requires a health questionnaire — a donor
// answers it the day before — so this reminder is the only thing standing
// between a donor booking and arriving at the counter with nothing to scan.
// Without it they are turned back to a form they could have filled at home.
//
// 08:00 so the day-before reminder arrives with an evening still left to act
// on, and the second one on the morning of the appointment lands before the
// donor sets off. The command is idempotent per donor, appointment and stage,
// so a retried or overlapping run cannot mail anyone twice.
Schedule::command('donors:open-screening-window')
    ->dailyAt('08:00')
    ->timezone(config('blood_center.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// A hospital blood bank's Tag Assigned and Tag Crossmatched holds each last 24
// hours. Without this, a lapsed tag keeps a bag promised to a patient nobody is
// crossmatching or transfusing, and the shelf looks emptier than it is. Writes
// already refuse a tag at its deadline, so the minute is how quickly the bag
// visibly frees up — not what makes the deadline hold.
//
// The overlap lock expires after 10 minutes rather than the default day: one
// crashed run must not stop the untagging until tomorrow.
Schedule::command('hospital:expire-tags')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();

// The hospital's own shelf, expired the way the centre's is and at the same
// moment. A separate command so inventory:expire-units stays exactly as it was:
// a received bag still reads `issued` at its centre and is not that sweep's.
Schedule::command('hospital:expire-units')
    ->dailyAt('00:30')
    ->timezone(config('blood_center.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// A facility's minimum stock means nothing if nobody is told when the shelf
// drops below it. The banner is always live, but the notification is only sent
// by this sweep, so ScheduleRegistrationTest fails the build if it is dropped.
//
// Every minute, because a shortage is not a thing to learn about tomorrow. The
// sweep is idempotent per low episode, so a minute costs one cheap read per
// facility and sends nothing while a cell stays low. The overlap lock expires
// after 10 minutes, as the tag sweep's does, so one crashed run cannot silence
// alerts for a day.
Schedule::command('inventory:check-thresholds')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();
