<?php

namespace App\Console\Commands;

use App\Service\SemaphoreSms;
use Illuminate\Console\Command;
use Throwable;

/**
 * Send one text through Semaphore to check a deployment's SMS settings.
 *
 * Sent directly, not queued, so Semaphore's answer is printed here. Each run
 * costs one credit per 160 characters.
 */
class SendTestSms extends Command
{
    protected $signature = 'sms:test
                            {number : A Philippine mobile number, 09… or +639…}
                            {--message= : The text to send instead of the default}';

    protected $description = 'Send a test text message through Semaphore';

    public function __construct(private readonly SemaphoreSms $sms)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->sms->isConfigured()) {
            $this->error('SMS is off: set SEMAPHORE_API_KEY (or SEMAPHORE_LOG_ONLY=true) in this environment.');

            return self::FAILURE;
        }

        $number = (string) $this->argument('number');

        if (SemaphoreSms::recipient($number) === null) {
            $this->error('Not a Philippine mobile number: '.$number);

            return self::FAILURE;
        }

        $message = trim((string) $this->option('message'))
            ?: 'RedAgos: This is a demo text from '.config('app.name').'. SMS notices to donors are working.';

        // Semaphore silently drops any message that starts with the word "TEST".
        if (preg_match('/^test\b/i', $message) === 1) {
            $this->error('Semaphore silently drops a message that starts with "TEST". Reword it.');

            return self::FAILURE;
        }

        if ($this->sms->logsOnly()) {
            $this->line('Log only: the text goes to the application log, not to the phone.');
        } else {
            try {
                $account = $this->sms->account();
                $this->line('Account: '.($account['account_name'] ?? '?').', '.($account['credit_balance'] ?? '?').' credits');
            } catch (Throwable) {
                // The account endpoint allows two calls a minute; the send does not depend on it.
                $this->line('Account: could not be read just now.');
            }
        }

        try {
            $sent = $this->sms->send($number, $message);
        } catch (Throwable $exception) {
            $this->error('Not sent: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($this->sms->logsOnly()) {
            $this->info('Logged for '.$sent['recipient'].' in storage/logs/laravel.log. Nothing was sent.');

            return self::SUCCESS;
        }

        $this->info('Sent to '.($sent['recipient'] ?? $number).' as message '.$sent['message_id'].', status '.($sent['status'] ?? '?').'.');

        return self::SUCCESS;
    }
}
