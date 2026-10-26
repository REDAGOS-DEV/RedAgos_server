<?php

namespace App\Http\Middleware;

use App\Models\PaymentProviderEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admit a webhook only when it carries this account's Xendit callback token.
 *
 * Xendit sends the account's verification token in the x-callback-token
 * header of every webhook. It is compared in constant time against the
 * configured token; a missing or wrong token, or no token configured, is a
 * 401. The refusal is recorded with nothing but the body's hash, and never
 * takes the delivery's dedupe key, so a forged copy cannot make the genuine
 * delivery look like a duplicate.
 */
class VerifyXenditCallbackToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.xendit.webhook_token');
        $given = (string) $request->header('x-callback-token', '');

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            PaymentProviderEvent::query()->create([
                'provider' => 'xendit',
                'body_hash' => hash('sha256', $request->getContent()),
                'dedupe_key' => null,
                'token_valid' => false,
                'outcome' => 'rejected',
                'received_at' => now(),
            ]);

            Log::warning('xendit.webhook_rejected', [
                'ip' => $request->ip(),
                'token_configured' => $expected !== '',
            ]);

            return response()->json([
                'message' => 'Invalid callback token.',
                'code' => 'invalid_callback_token',
            ], 401);
        }

        return $next($request);
    }
}
