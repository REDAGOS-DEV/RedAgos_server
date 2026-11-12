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
 * A drive someone is on the list for (through its registration link) has
 * moved. Sent on demand to the email and mobile number on the entry: a person
 * on the list may hold no account. DriveRescheduled tells the donors who had
 * booked.
 */
class DriveRescheduledForRegistrant extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string|null  $firstName  the entry's, so a text to a household's
     *                                  shared phone says whose it is
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

        if (SemaphoreSms::recipient($notifiable->routeNotificationFor('sms', $this)) !== null && app(SemaphoreSms::class)->isConfigured()) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    /**
     * Build the notice.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Blood drive moved: '.$this->drive->name)
            ->greeting('This blood drive has moved')
            ->line($this->centre().' has moved '.$this->drive->name.'.')
            ->line('**When:** '.$this->drive->event_date->format('l, j F Y').', '.$this->hours())
            ->line('**Where:** '.$this->drive->location)
            ->line('You are still on the list for this drive. You do not need to register again.')
            ->line('Questions? Call us on '.config('donation.support.hotline_label').' ('.config('donation.support.hours').').');
    }

    /**
     * Build the text message, one SMS.
     */
    public function toSms(object $notifiable): string
    {
        return SmsName::open($this->firstName, $this->centre().' has moved ').$this->drive->name
            .' to '.$this->drive->event_date->format('j M Y').', '.$this->hours()
            .'. You are still on the list.';
    }

    private function centre(): string
    {
        return $this->drive->facility?->name ?? 'The blood centre';
    }

    /** "8:00 AM to 3:00 PM" from the stored 'HH:MM' wall-clock times. */
    private function hours(): string
    {
        $format = fn (?string $time): ?string => $time ? date('g:i A', strtotime($time)) : null;
        $start = $format($this->drive->start_time);
        $end = $format($this->drive->end_time);

        return match (true) {
            $start !== null && $end !== null => $start.' to '.$end,
            $start !== null => 'from '.$start,
            default => 'times to be announced',
        };
    }
}
