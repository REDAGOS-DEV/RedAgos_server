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
