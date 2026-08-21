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

    /*
    | Development only. Allows any RFC1918 private-network origin so that
    | cross-device testing survives a LAN IP change (hotspot 172.x, home
    | Wi-Fi 192.168.x) without editing .env. Off by default outside local —
    | enabling it in production requires setting the flag explicitly.
    */
    'allowed_origins_patterns' => array_values(array_filter([
        env('CORS_ALLOW_PRIVATE_NETWORK', env('APP_ENV') === 'local')
            ? '#^http://(?:10\.\d{1,3}\.\d{1,3}\.\d{1,3}'
                .'|172\.(?:1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}'
                .'|192\.168\.\d{1,3}\.\d{1,3}'
                .'|localhost|127\.0\.0\.1)'
                .':\d{1,5}$#'
            : null,
    ])),


    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
    | Bearer tokens are sent in the Authorization header rather than cookies,
    | so credentialed requests are not required.
    */
    'supports_credentials' => false,

];
