<?php

namespace App\Service;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The only code that talks to Semaphore, the SMS gateway.
 *
 * Messages go to POST /messages, form-encoded, as Semaphore's documentation
 * shows them. The API key never leaves this class: it is not logged, not
 * returned, and not sent anywhere but Semaphore.
 *
 * Sending is never retried here. Semaphore charges per message, so a retry
 * after a timeout could text the donor twice; the queued notification's own
 * failure is the record of it.
 */
class SemaphoreSms
{
    /**
     * Whether SMS is switched on: an API key is set, or texts only go to the log.
     */
    public function isConfigured(): bool
    {
        return $this->logsOnly() || (string) config('services.semaphore.api_key') !== '';
    }

    /**
     * Whether texts are written to the log instead of sent (SEMAPHORE_LOG_ONLY).
     */
    public function logsOnly(): bool
    {
        return (bool) config('services.semaphore.log_only');
    }

    /**
     * The number in the form Semaphore takes (639XXXXXXXXX), or null when it is
     * not a Philippine mobile.
     *
     * users.phone is E.164, but older rows may still be local (09…), and a
     * landline is stored too. Landlines cannot receive a text, so they are null.
     */
    public static function recipient(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (preg_match('/^09\d{9}$/', $digits) === 1) {
            return '63'.substr($digits, 1);
        }

        return preg_match('/^639\d{9}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Send one text and return Semaphore's record of it (message_id, status, recipient…).
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException|RuntimeException
     */
    public function send(string $phone, string $message): array
    {
        $number = self::recipient($phone)
            ?? throw new RuntimeException('Not a Philippine mobile number: '.$phone);

        if ($this->logsOnly()) {
            Log::info('SMS not sent (SEMAPHORE_LOG_ONLY)', ['to' => $number, 'message' => $message]);

            return ['message_id' => 'log-only', 'recipient' => $number, 'status' => 'Logged'];
        }

        $payload = array_filter([
            'apikey' => $this->apiKey(),
            'number' => $number,
            'message' => $message,
            'sendername' => (string) config('services.semaphore.sender_name'),
        ], fn (string $value): bool => $value !== '');

        $sent = (array) $this->client()
            ->asForm()
            ->post('/messages', $payload)
            ->throw()
            ->json();

        // One recipient, so one record. A body that is not a list of records is
        // Semaphore refusing the message (an unapproved sender name, no credit).
        $record = $sent[0] ?? null;

        if (! is_array($record) || ! isset($record['message_id'])) {
            throw new RuntimeException('Semaphore did not accept the message: '.json_encode($sent));
        }

        return $record;
    }

    /**
     * The account as Semaphore has it: account_name, status, credit_balance.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|RuntimeException
     */
    public function account(): array
    {
        try {
            return (array) $this->client()
                ->get('/account', ['apikey' => $this->apiKey()])
                ->throw()
                ->json();
        } catch (ConnectionException) {
            // The key is in this request's query string, and a connection
            // error's message quotes the full URL.
            throw new RuntimeException('Could not reach Semaphore.');
        }
    }

    private function apiKey(): string
    {
        $key = (string) config('services.semaphore.api_key');

        if ($key === '') {
            throw new RuntimeException('Semaphore is not configured: SEMAPHORE_API_KEY is empty.');
        }

        return $key;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.semaphore.base_url'), '/'))
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5);
    }
}
