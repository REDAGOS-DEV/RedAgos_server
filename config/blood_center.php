<?php

// Where each kind of bag has to be kept: see Storage Areas below.
$storageAreas = [
    'refrigerator' => [
        'locations' => [
            'Cold Storage A-1',
            'Cold Storage A-2',
            'Cold Storage A-3',
            'Cold Storage B-1',
            'Cold Storage B-2',
            'Cold Storage C-1',
        ],
        'components' => ['Whole Blood', 'Packed RBC', 'Washed RBC'],
    ],
    'freezer' => [
        'locations' => ['Freezer A', 'Freezer B'],
        'components' => ['Fresh Frozen Plasma', 'Cryoprecipitate', 'Cryosupernate'],
    ],
    'platelet_agitator' => [
        'locations' => ['Platelet Agitator 1'],
        'components' => ['Platelet Concentrate'],
    ],
];

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
    | Storage Areas
    |--------------------------------------------------------------------------
    |
    | Where each kind of bag has to be kept, and the shelves in each place.
    | Stock Intake offers a bag only the shelves of its component's area
    | (project owner, 2026-11-27): red cells in a blood bank refrigerator at
    | 2-6 C, plasma products in a freezer at -18 C or colder, platelets on an
    | agitator at 20-24 C.
    |
    | `components` are catalogue names, matched without regard to case. A
    | component no area names (a centre's own) is offered every location.
    |
    */

    'storage_areas' => $storageAreas,

    /*
    |--------------------------------------------------------------------------
    | Storage Locations
    |--------------------------------------------------------------------------
    |
    | The physical locations a blood unit may be stored in, served to the
    | inventory filters as reference data.
    |
    | These are display values only and constrain nothing: every Storage Area's
    | shelves, in order. The inventory screens union this list with
    | the distinct storage_location values actually recorded against units, so a
    | centre that uses its own labels is never forced onto these.
    |
    */

    'storage_locations' => array_merge(...array_column($storageAreas, 'locations')),

    /*
    |--------------------------------------------------------------------------
    | Daily Blood Stock Inventory
    |--------------------------------------------------------------------------
    |
    | The report laid out as SNBC-Mindanao's daily sheet: three tables for the
    | components the sheet names, and an "other components" table for the rest.
    |
    | `header` is printed above the facility's own name. It is the Davao Center
    | for Health Development's for now, because every named institution in the
    | study sits under it; a per-facility regional office is a later change.
    |
    | `roles` names which catalogue component fills each place on the sheet.
    | A component not named here is an extra.
    |
    | `reference_component` sets the dated-column rule: a component gets one
    | column per expiry date when its configured shelf life is at or under this
    | component's, at this facility. Red cells and platelets fall under it;
    | frozen plasma products do not. `dated_fallback_days` stands in when the
    | reference component itself has no shelf life configured.
    |
    */

    'stock_report' => [
        'header' => [
            'Republic of the Philippines',
            'Department of Health',
            'Davao Center for Health Development',
        ],

        'roles' => [
            'prbc' => 'Packed RBC',
            'platelets' => 'Platelet Concentrate',
            'ffp' => 'Fresh Frozen Plasma',
            'cryo' => 'Cryoprecipitate',
            'csp' => 'Cryosupernate',
        ],

        'reference_component' => 'Packed RBC',

        'dated_fallback_days' => 42,

        // Bundled with the application, not uploaded: the same seal for every
        // facility under the Department of Health.
        'seal_path' => resource_path('images/doh-seal.png'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cash Shifts at the Billing Counter
    |--------------------------------------------------------------------------
    |
    | Off, the counter takes payments without a shift: the owner decided on
    | 2026-10-11 that with one billing staff member there is no drawer to hand
    | over, and a void is allowed on the day the payment was recorded.
    |
    | On, every counter payment goes into the cashier's open shift, the shift
    | is closed with the drawer counted, and a void is allowed only while the
    | payment's shift is still open. Turn it on once more than one cashier
    | shares the counter.
    |
    */

    'cash_shifts' => (bool) env('BILLING_CASH_SHIFTS', false),

];
