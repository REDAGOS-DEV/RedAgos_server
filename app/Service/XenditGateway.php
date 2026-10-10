<?php

namespace App\Service;

use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The only code that talks to Xendit.
 *
 * Uses the Payment Sessions API (POST /sessions, GET /sessions/{id}) with the
 * RedAgos master key, acting for one blood centre's XenPlatform sub-account
 * through the for-user-id header. The secret key never leaves this class: it
 * is not logged, not returned, and not sent anywhere but Xendit.
 *
 * Creating a session is never retried — a retry could open a second checkout
 * for the same attempt. Reading one is idempotent, so a connection failure or
 * a server error gets two quick retries before the caller is told.
 *
 * Every amount Xendit sends back is turned into centavos here, in centavos(),
 * and handled as an integer from then on.
 */
class XenditGateway
{
    /**
     * Open a hosted checkout session on a sub-account's behalf.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function createSession(string $subAccountId, array $payload): array
    {
        return (array) $this->client($subAccountId)
            ->post('/sessions', $payload)
            ->throw()
            ->json();
    }

    /**
     * Read a session as Xendit has it now: the server's only source of truth on whether it was paid.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getSession(string $subAccountId, string $sessionId): array
    {
        return (array) $this->client($subAccountId)
            ->retry([200, 1000], when: fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()))
            ->get('/sessions/'.rawurlencode($sessionId))
            ->throw()
            ->json();
    }

    /**
     * Convert an amount Xendit reported into centavos, or null when it is not an exact peso amount.
     */
    public function centavos(mixed $amount): ?int
    {
        if (! is_int($amount) && ! is_float($amount) && ! is_string($amount)) {
            return null;
        }

        try {
            return Money::toCentavos($amount);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A request authenticated as the master account, acting for one sub-account.
     */
    private function client(string $subAccountId): PendingRequest
    {
        $secret = (string) config('services.xendit.secret_key');

        if ($secret === '') {
            throw new RuntimeException('Xendit is not configured: XENDIT_SECRET_KEY is empty.');
        }

        return Http::baseUrl(rtrim((string) config('services.xendit.base_url'), '/'))
            ->withBasicAuth($secret, '')
            ->withHeaders(['for-user-id' => $subAccountId])
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->connectTimeout(5);
    }
}
