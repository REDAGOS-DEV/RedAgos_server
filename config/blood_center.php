<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operational Timezone
    |--------------------------------------------------------------------------
    |
    | The clock every date comparison in inventory resolves through: the expiry
    | sweep, the expiry_date validation rules, and days_remaining in the
    | listing. They must not each ask a different clock.
    |
    | Expiry is a date, and a date is only meaningful in a timezone. Under UTC,
    | Manila's 00:00-08:00 is still "yesterday", so a sweep scheduled for 00:30
    | Manila would compute the previous day and expire everything a day late.
    | Every named institution in the study is in Davao City, so one value serves
    | all of them; this is the single function to change if that stops being
    | true.
    |
    */

    'timezone' => env('BLOOD_CENTER_TIMEZONE', 'Asia/Manila'),

    /*
    |--------------------------------------------------------------------------
    | Storage Locations
    |--------------------------------------------------------------------------
    |
    | The physical locations a blood unit may be stored in, served to the
    | inventory filters as reference data.
    |
    | These are display values only and constrain nothing. The defaults are the
    | labels the frontend already hardcoded. Module 2 will union this list with
    | the distinct storage_location values actually recorded against units, so a
    | centre that uses its own labels is never forced onto these.
    |
    */

    'storage_locations' => [
        'Cold Storage A-1',
        'Cold Storage A-2',
        'Cold Storage A-3',
        'Cold Storage B-1',
        'Cold Storage B-2',
        'Cold Storage C-1',
        'Freezer A',
        'Freezer B',
        'Platelet Agitator 1',
    ],

];
