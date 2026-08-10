<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Donor Eligibility Thresholds
    |--------------------------------------------------------------------------
    |
    | Server-authoritative rules for preliminary donor screening. The frontend
    | questionnaire displays its own copy of these numbers, but the values here
    | are the only ones that decide an outcome.
    |
    */

    'min_age_years' => env('DONATION_MIN_AGE_YEARS', 18),

    'min_weight_kg' => env('DONATION_MIN_WEIGHT_KG', 50),

    /*
    |--------------------------------------------------------------------------
    | The Three Independent Time Rules
    |--------------------------------------------------------------------------
    |
    | These are distinct concepts and must never be collapsed into one another:
    |
    |   interval_days   How long a donor must wait between whole-blood
    |                   donations. Measured from the last completed donation.
    |   screening_validity_days
    |                   How long a passed preliminary screening stands before
    |                   the donor must answer the questionnaire again.
    |   qr_validity_days
    |                   How long an issued check-in token can be presented. A
    |                   donor whose screening is still valid but whose token has
    |                   expired refreshes the token without re-screening.
    |
    | Screening validity is NOT a substitute for the donation interval.
    |
    */

    'interval_days' => env('DONATION_INTERVAL_DAYS', 56),

    'screening_validity_days' => env('DONATION_SCREENING_VALIDITY_DAYS', 90),

    'qr_validity_days' => env('DONATION_QR_VALIDITY_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Appointment Booking
    |--------------------------------------------------------------------------
    |
    | How far ahead a donor may book, and how close to the appointment they may
    | still cancel or reschedule it.
    |
    */

    'booking_horizon_days' => env('DONATION_BOOKING_HORIZON_DAYS', 90),

    'cancellation_window_hours' => env('DONATION_CANCELLATION_WINDOW_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Questionnaire Version
    |--------------------------------------------------------------------------
    |
    | The question bank version served to donors. Submissions quoting an older
    | version are rejected so that answers always map to the wording shown.
    |
    */

    'questionnaire_version' => env('DONATION_QUESTIONNAIRE_VERSION', 1),

    /*
    |--------------------------------------------------------------------------
    | Derived Display Figures
    |--------------------------------------------------------------------------
    |
    | Lives potentially helped per completed donation, used by the donation
    | history summary so the figure is consistent across screens.
    |
    */

    'lives_per_donation' => env('DONATION_LIVES_PER_DONATION', 3),

    /*
    |--------------------------------------------------------------------------
    | Donor Support Contact
    |--------------------------------------------------------------------------
    */

    'support' => [
        'hotline' => env('SUPPORT_HOTLINE', '+63281234567'),
        'hotline_label' => env('SUPPORT_HOTLINE_LABEL', '(02) 8123-4567'),
        'email' => env('SUPPORT_EMAIL', 'support@redagos.example'),
        'hours' => env('SUPPORT_HOURS', 'Mon - Sat, 8:00 AM - 5:00 PM'),
    ],

];
