<?php

return [

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
