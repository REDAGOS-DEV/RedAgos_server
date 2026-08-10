<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The Nuxt SPA runs on a different origin to this API (:3000 vs :8000), so
    | every browser request to /api/* is cross-origin. Allowed origins are
    | driven by FRONTEND_URL so no host is hardcoded here.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', (string) env('FRONTEND_URL', 'http://localhost:3000'))))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
    | Bearer tokens are sent in the Authorization header rather than cookies,
    | so credentialed requests are not required.
    */
    'supports_credentials' => false,

];
