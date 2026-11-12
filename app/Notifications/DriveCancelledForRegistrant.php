<?php

namespace App\Notifications;

use App\Models\MobileEvent;
use App\Notifications\Channels\SmsChannel;
use App\Service\SemaphoreSms;
use App\Support\SmsName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A drive someone put themselves (or was put by a partner) on the list for has
 * been cancelled. Sent on demand to the email and mobile number on the entry:
 * a person on the list may hold no account, so there is no in-app copy and no
 * preference to read. DriveCancelled tells the donors who had booked.
 */
class DriveCancelledForRegistrant extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string|null  $firstName  the entry's, so a text to a household's
     *                                  shared phone says whose it is (2026-11-12)
     */
    public function __construct(
        private readonly MobileEvent $drive,
        private readonly ?string $firstName = null
    ) {}

    /**
     * Mail to an email on the entry; a text to a mobile number, when SMS is set up.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (filled($notifiable->routeNotificationFor('mail', $this))) {
            $channels[] = 'mail';
        }

        $phone = $notifiable->routeNotificationFor('sms', $this);

        if (SemaphoreSms::recipient($phone) !== null && app(SemaphoreSms::class)->isConfigured()) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    /**
     * Build the cancellation notice.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Blood drive cancelled: '.$this->drive->name)
            ->greeting('This blood drive is cancelled')
            ->line($this->centre().' has cancelled '.$this->drive->name.' on '.$this->drive->event_date->format('l, j F Y').' at '.$this->drive->location.'.');

        if (filled($this->drive->cancellation_reason)) {
            $mail->line('**Reason:** '.$this->drive->cancellation_reason);
        }

        return $mail
            ->line('You were on the list for this drive. You do not need to do anything.')
            ->line('Questions? Call us on '.config('donation.support.hotline_label').' ('.config('donation.support.hours').').');
    }

    /**
     * Build the text message, without the reason, to keep it one SMS.
     */
    public function toSms(object $notifiable): string
    {
        return SmsName::open($this->firstName, $this->centre().' has cancelled ').$this->drive->name
            .' on '.$this->drive->event_date->format('j M Y')
            .'. You were on the list for this drive. You do not need to do anything.';
    }

    private function centre(): string
    {
        return $this->drive->facility?->name ?? 'The blood centre';
    }
}
