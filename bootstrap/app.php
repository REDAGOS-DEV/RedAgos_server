<?php

use App\Http\Middleware\EnsureBloodCenterOperational;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\VerifyXenditCallbackToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RequireRole::class,
            'facility.operational' => EnsureBloodCenterOperational::class,
            'xendit.callback' => VerifyXenditCallbackToken::class,
        ]);

        // Every API route: an account on the counter's temporary password may
        // only set its own (see the middleware for the few exceptions).
        $middleware->api(append: [
            EnsurePasswordChanged::class,
        ]);

        // Deployed, every request arrives through the host's proxy (Laravel
        // Cloud runs behind Cloudflare), which ends HTTPS and passes the
        // visitor's address on in X-Forwarded-*. Untrusted, every visitor would
        // share the proxy's IP: one person's failed sign-ins would throttle
        // everyone's (`throttle:5,1` on /login), the audit log would record the
        // proxy, and absolute links would say http://. `*` trusts whichever
        // proxy calls, which is safe because the deployed app is reachable only
        // through it.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
