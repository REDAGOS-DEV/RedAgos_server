<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email `php artisan mail:test` sends, on the same template as every
 * donor notice, so what arrives is what donors will see.
 *
 * Queued like the real notices; the command sends it now unless asked to go
 * through the queue worker.
 */
class MailDeliveryCheck extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly bool $queued) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('RedAgos email check')
            ->greeting('Email is working.')
            ->line('This message was sent by '.config('app.name').' from '.config('app.url').' through '.config('mail.default').'.')
            ->line($this->queued
                ? 'It went through the queue worker, so donor notices will be delivered too.'
                : 'It was sent directly, not through the queue worker. Run the command with --queued to check the worker as well.')
            ->action('Open RedAgos', rtrim((string) config('app.frontend_url'), '/'));
    }
}
