<?php

namespace App\Notifications;

use App\Models\MobileEvent;
use App\Support\DonorNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A blood centre the donor has given at, or booked with, scheduled a drive.
 *
 * Sent by BloodDriveAnnouncer when the drive is created. Both copies are the
 * donor's choice in Settings ("Blood drives"): in-app on by default, email
 * off, since a centre may run several drives a month. Queued like the other
 * donor notices; see DonationRecorded.
 */
class BloodDriveScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly MobileEvent $drive
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return DonorNotificationPreferences::optionalChannels(
            $notifiable,
            DonorNotificationPreferences::DRIVES_IN_APP,
            DonorNotificationPreferences::DRIVES_EMAIL,
        );
    }

    /**
     * Only the mail waits for the queue worker; the in-app copy is written at once.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject('Blood drive on '.$this->shortDate().': '.$this->drive->name)
            ->greeting('A blood drive is coming up')
            ->line($this->centre().' is holding a blood drive you can book.')
            ->line('**What:** '.$this->drive->name)
            ->line('**When:** '.$this->longDate().', '.$this->hours())
            ->line('**Where:** '.$this->drive->location)
            ->action('Book a slot', $frontend.'/donor/appointments')
            ->line('You get these because you have donated or booked with '.$this->centre().'. You can turn them off in Settings, under Notifications.');
    }

    /**
     * Build the in-app copy the donor's notifications screen lists.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            // One of DonorNotificationService::CATEGORIES: an invitation to
            // book, which the screen files with the other reminders.
            'category' => 'reminder',
            'title' => 'Blood drive: '.$this->drive->name,
            'desc' => $this->longDate().', '.$this->hours().' at '.$this->drive->location.'.',
            'meta' => 'By '.$this->centre(),
            'icon' => 'map-pin',
            'tone' => 'info',
            'action_label' => 'Book a slot',
            'action_route' => '/donor/appointments',
            'mobile_event_id' => $this->drive->id,
        ];
    }

    private function centre(): string
    {
        return $this->drive->facility?->name ?? 'Your blood centre';
    }

    private function shortDate(): string
    {
        return $this->drive->event_date->format('j M Y');
    }

    private function longDate(): string
    {
        return $this->drive->event_date->format('l, j F Y');
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
