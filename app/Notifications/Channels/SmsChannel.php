<?php

namespace App\Notifications\Channels;

use App\Service\SemaphoreSms;
use Illuminate\Notifications\Notification;

/**
 * Texts a notice to the account's mobile number through Semaphore.
 *
 * A notice picks this channel through DonorNotificationPreferences, which only
 * does so when SMS is configured and the donor holds a mobile number, and it
 * supplies the text from its own toSms(). A Semaphore error is thrown, so the
 * queued job fails where the queue's failed-jobs list shows it.
 */
class SmsChannel
{
    public function __construct(private readonly SemaphoreSms $sms) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $phone = $notifiable->routeNotificationFor('sms', $notification) ?? $notifiable->phone ?? null;

        if (SemaphoreSms::recipient($phone) === null || ! method_exists($notification, 'toSms')) {
            return;
        }

        $this->sms->send($phone, $notification->toSms($notifiable));
    }
}
