<?php

namespace App\Console\Commands;

use App\Notifications\MailDeliveryCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Send one email to check that a deployment's mail settings deliver.
 *
 * Sent directly by default, so a wrong host, port or password is reported here
 * rather than in the failed-jobs list. --queued sends it the way donor notices
 * go, through the queue worker, which is the other half that must work.
 */
class SendTestMail extends Command
{
    protected $signature = 'mail:test
                            {address : Where to send it}
                            {--queued : Send through the queue worker, as donor notices are}';

    protected $description = 'Send a test email to check the mail settings';

    public function handle(): int
    {
        $address = (string) $this->argument('address');

        if (Validator::make(['address' => $address], ['address' => 'email:rfc'])->fails()) {
            $this->error('Not an email address: '.$address);

            return self::FAILURE;
        }

        $this->line('Mailer: '.config('mail.default').' ('.(config('mail.mailers.'.config('mail.default').'.host') ?? 'no host').')');
        $this->line('From: '.config('mail.from.name').' <'.config('mail.from.address').'>');

        $queued = (bool) $this->option('queued');
        $route = Notification::route('mail', $address);

        try {
            $queued
                ? $route->notify(new MailDeliveryCheck(queued: true))
                : $route->notifyNow(new MailDeliveryCheck(queued: false));
        } catch (Throwable $exception) {
            $this->error('Not sent: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($queued) {
            $this->info('Queued for '.$address.' on the '.config('queue.default').' connection. It arrives once the queue worker picks it up.');
        } else {
            $this->info('Sent to '.$address.'. Check the inbox, and the spam folder.');
        }

        return self::SUCCESS;
    }
}
