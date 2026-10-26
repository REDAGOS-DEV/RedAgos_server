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
    | The Counter's Vital Signs (project owner, 2026-10-24)
    |--------------------------------------------------------------------------
    |
    | The six readings the screening physician must record at the counter
    | before accepting or deferring, and the range each is normal within
    | (ScreeningVitals). A reading outside its range suggests the deferral
    | code beside it, if the centre still offers that code.
    |
    | min/max may be a number, or one per sex. A donor whose sex is not
    | recorded as male or female is held to the stricter of the two, so a
    | reading that may be out of range is never passed over.
    |
    | vital_only_codes are offered only while a reading suggests them: with
    | normal readings a donor can still be deferred, for a history reason
    | (tattoo, medication, travel...) or Other, but not under these.
    |
    */

    'vitals' => [
        'systolic_bp' => ['label' => 'Systolic BP', 'unit' => 'mmHg', 'min' => 90, 'max' => 160, 'low_code' => 'R7', 'high_code' => 'R7'],
        'diastolic_bp' => ['label' => 'Diastolic BP', 'unit' => 'mmHg', 'min' => 60, 'max' => 100, 'low_code' => 'R7', 'high_code' => 'R7'],
        'pulse_bpm' => ['label' => 'Pulse', 'unit' => 'bpm', 'min' => 60, 'max' => 100, 'low_code' => null, 'high_code' => null],
        'temperature_c' => ['label' => 'Temperature', 'unit' => '°C', 'min' => null, 'max' => 37.5, 'low_code' => null, 'high_code' => 'R3'],
        'weight_kg' => ['label' => 'Weight', 'unit' => 'kg', 'min' => env('DONATION_MIN_WEIGHT_KG', 50), 'max' => null, 'low_code' => 'R8', 'high_code' => null],
        'haemoglobin_g_dl' => ['label' => 'Haemoglobin', 'unit' => 'g/dL', 'min' => ['male' => 13.5, 'female' => 12.5], 'max' => 17.5, 'low_code' => 'R2-Low', 'high_code' => 'R2-High'],
    ],

    'vital_only_codes' => ['R2-Low', 'R2-High', 'R7', 'R8'],

    /*
    |--------------------------------------------------------------------------
    | The Four Independent Time Rules
    |--------------------------------------------------------------------------
    |
    | These are distinct concepts and must never be collapsed into one another:
    |
    |   interval_days   How long a donor must wait between whole-blood
    |                   donations. Measured from the last completed donation.
    |   screening_validity_days
    |                   How long an answered questionnaire stands before the
    |                   donor must answer it again.
    |   qr_validity_days
    |                   How long an issued check-in token can be presented. A
    |                   donor whose screening is still valid but whose token has
    |                   expired refreshes the token without re-screening.
    |   appointment_screening_window_days
    |                   How many days before a booked appointment the donor may
    |                   answer the questionnaire at all. Distinct from
    |                   screening_validity_days: that one is how long an answer
    |                   stands, this one is how early it may be given. A donor
    |                   with no appointment is not subject to it.
    |
    | Screening validity is NOT a substitute for the donation interval.
    |
    */

    'interval_days' => env('DONATION_INTERVAL_DAYS', 56),

    'screening_validity_days' => env('DONATION_SCREENING_VALIDITY_DAYS', 90),

    'qr_validity_days' => env('DONATION_QR_VALIDITY_DAYS', 14),

    'appointment_screening_window_days' => env('DONATION_APPOINTMENT_SCREENING_WINDOW_DAYS', 1),

    /*
    |--------------------------------------------------------------------------
    | Appointment Booking
    |--------------------------------------------------------------------------
    |
    | How far ahead a donor may book, and how close to the appointment they may
    | still cancel or reschedule it.
    |
    | The window is 0 while the blood centre runs on walk-ins: nothing is
    | planned around a booking yet, so locking it only turns a donor who cannot
    | come into a silent no-show. A late cancel at least frees the slot and tells
    | the centre. Raise it once the centre schedules staff around appointments.
    |
    */

    'booking_horizon_days' => env('DONATION_BOOKING_HORIZON_DAYS', 90),

    'cancellation_window_hours' => env('DONATION_CANCELLATION_WINDOW_HOURS', 0),

    /*
    |--------------------------------------------------------------------------
    | Questionnaire Version
    |--------------------------------------------------------------------------
    |
    | The question bank version served to donors. Submissions quoting an older
    | version are rejected so that answers always map to the wording shown.
    |
    */

    'questionnaire_version' => env('DONATION_QUESTIONNAIRE_VERSION', 2),

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
