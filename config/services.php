<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Xendit (GCash checkout for Patient Transfusion statements)
    |--------------------------------------------------------------------------
    |
    | One RedAgos master account; each blood centre collects into its own
    | XenPlatform sub-account (facilities.xendit_sub_account_id), sent as the
    | for-user-id header. Test and live keys are separate Xendit keys: the key
    | in use decides the mode, so a test key here can never move real money.
    |
    | checkout_enabled switches off NEW checkouts only — the rollback lever.
    | Webhooks, verification and reconciliation keep running regardless, so a
    | checkout already open always settles.
    |
    | allow_main_account is for local testing before XenPlatform is approved:
    | a centre without a sub-account collects into the master account itself.
    | It only takes effect with a test key (xnd_development_…), so it can never
    | send a centre's real money to the RedAgos account.
    |
    | webhook_events maps the machine event names this account actually
    | delivers onto the three things the processor cares about. The defaults
    | are the names in Xendit's documentation; confirm them in the sandbox and
    | override per environment. The processor never trusts an event name on its
    | own: every claim is verified by re-fetching the session.
    |
    */
    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
        'base_url' => env('XENDIT_API_BASE_URL', 'https://api.xendit.co'),
        'checkout_enabled' => (bool) env('XENDIT_CHECKOUT_ENABLED', false),
        'allow_main_account' => (bool) env('XENDIT_ALLOW_MAIN_ACCOUNT', false),
        'session_ttl_minutes' => (int) env('XENDIT_SESSION_TTL_MINUTES', 30),
        'verification_grace_minutes' => (int) env('XENDIT_VERIFICATION_GRACE_MINUTES', 60),
        // GCash's documented per-transaction ceiling, in pesos.
        'max_amount' => env('XENDIT_MAX_AMOUNT', '100000.00'),
        'channel' => env('XENDIT_CHANNEL', 'GCASH'),
        // Where the payer's phone lands after paying. A plain page that
        // claims nothing: payment is confirmed by the server, not the redirect.
        'return_url' => env('XENDIT_RETURN_URL') ?: rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/').'/payment/complete',
        // Null keeps webhook event rows indefinitely; a number purges older ones daily.
        'event_retention_days' => env('XENDIT_EVENT_RETENTION_DAYS'),
        'webhook_events' => [
            'completed' => array_values(array_filter(explode(',', (string) env(
                'XENDIT_EVENTS_COMPLETED',
                'payment_session.completed,payment.succeeded,payment.capture'
            )))),
            'expired' => array_values(array_filter(explode(',', (string) env(
                'XENDIT_EVENTS_EXPIRED',
                'payment_session.expired'
            )))),
            'failed' => array_values(array_filter(explode(',', (string) env(
                'XENDIT_EVENTS_FAILED',
                'payment.failure'
            )))),
        ],
    ],

];
