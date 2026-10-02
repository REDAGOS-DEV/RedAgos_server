<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Donor's Informed Consent
    |--------------------------------------------------------------------------
    |
    | Section I-C of the DOH Blood Donor's Health Questionnaire
    | (DOH-DCHD-RD-SNBC-DMS-FORM002), transcribed verbatim. A donor must accept
    | every statement of the current version before a questionnaire submission
    | is recorded.
    |
    | Declared here rather than in a database table for the same reason
    | App\Support\DepartmentPermissions gives: the wording is versioned with the
    | code that depends on it and is covered by a test, rather than by whatever
    | happens to be in a seeder on the day.
    |
    | Screenings store the version key and a SHA-256 of the statements they were
    | shown -- never the text itself. Duplicating a kilobyte of legal wording per
    | row guarantees it drifts from the canonical copy, and the hash already
    | proves which revision the donor read. When a stored version is no longer
    | listed here, the counter says so rather than substituting current wording:
    | today's text beside yesterday's timestamp is worse than a gap.
    |
    | This version is deliberately independent of
    | config('donation.questionnaire_version'). The consent wording and the
    | question bank change for different reasons and on different schedules.
    |
    */

    'current' => env('DONOR_CONSENT_VERSION', 'doh-2023-07'),

    'versions' => [

        'doh-2023-07' => [
            // The effectivity date printed on the form itself.
            'effective_from' => '2023-07-03',

            'statements' => [
                'I am the person referred to in all entries, which were read and well understood by me. It is my free and voluntary act to donate my blood, aware of its risks during and after extraction. The same has been explained to me in the understandable language and dialect that I speak.',

                'I am voluntarily giving my blood through Sub-National Blood Center – Mindanao and I understand that my blood will be tested for Blood Type, Hemoglobin, Malaria, Syphilis, Hepatitis B, Hepatitis C, and HIV and no official result will be issued to me. If found reactive, I agreed to be referred to the appropriate facility for counselling and for further management.',

                'I am allowing the Sub-National Blood Center – Mindanao and responsible authorities to access my data in accordance with the RA No. 10173 or the Data Privacy Act of 2012.',

                'All materials and data might be used for different medical research purposes.',

                'I certify that I have to the best of my knowledge, truthfully answered the above questions.',
            ],
        ],

    ],

];
