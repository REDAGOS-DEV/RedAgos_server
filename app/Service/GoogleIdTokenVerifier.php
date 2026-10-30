<?php

namespace App\Service;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks a Google sign-in credential (the ID token Google Identity Services
 * hands the browser) and returns the address it vouches for.
 *
 * Google's tokeninfo endpoint checks the signature and expiry; this checks the
 * rest: that the token was issued by Google, for this app's client ID, and for
 * an address Google has verified. No client secret is involved, so only
 * GOOGLE_CLIENT_ID needs setting.
 */
class GoogleIdTokenVerifier
{
    private const TOKENINFO = 'https://oauth2.googleapis.com/tokeninfo';

    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * @return string The verified address, in lower case.
     */
    public function verifiedEmail(string $credential): string
    {
        $clientId = (string) config('services.google.client_id');

        if ($clientId === '') {
            throw $this->refuse('Google sign-in is not set up on this server.', 'google_not_configured', 503);
        }

        try {
            $response = Http::timeout(10)->get(self::TOKENINFO, ['id_token' => $credential]);
        } catch (Throwable) {
            throw $this->refuse('Google could not be reached. Please try again.', 'google_unreachable', 503);
        }

        $claims = $response->successful() ? $response->json() : null;

        if (! is_array($claims)
            || ($claims['aud'] ?? null) !== $clientId
            || ! in_array($claims['iss'] ?? null, self::ISSUERS, true)
            || (int) ($claims['exp'] ?? 0) <= now()->timestamp) {
            throw $this->refuse('That Google sign-in could not be confirmed. Please try again.', 'google_token_invalid', 401);
        }

        // tokeninfo sends the flag as the string "true".
        $verified = $claims['email_verified'] ?? false;

        if (! filled($claims['email'] ?? null) || ! ($verified === true || $verified === 'true')) {
            throw $this->refuse('This Google account has no verified email address.', 'google_email_unverified', 401);
        }

        return strtolower((string) $claims['email']);
    }

    private function refuse(string $message, string $code, int $status): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
