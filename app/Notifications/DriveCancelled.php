<?php

namespace App\Notifications;

use App\Models\MobileEvent;
use App\Support\DonorNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A drive the donor was booked for has been cancelled, and their booking with
 * it. Sent by MobileEventService::cancel() after the cancellation commits.
 *
 * On the same channels as the booking confirmation (AppointmentScheduled): it
 * undoes a booking, so the in-app copy is always written and the email follows
 * the donor's appointment-email switch. Queued; see DonationRecorded.
 */
class DriveCancelled extends Notification implements ShouldQueue
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
        return DonorNotificationPreferences::alwaysInAppChannels($notifiable, DonorNotificationPreferences::APPOINTMENTS_EMAIL);
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

    /**
     * Build the cancellation notice addressed to the SPA.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $mail = (new MailMessage)
            ->subject('Blood drive cancelled: '.$this->drive->name)
            ->greeting('This blood drive is cancelled')
            ->line($this->centre().' has cancelled '.$this->drive->name.' on '.$this->longDate().' at '.$this->drive->location.'.');

        if (filled($this->drive->cancellation_reason)) {
            $mail->line('**Reason:** '.$this->drive->cancellation_reason);
        }

        return $mail
            ->line('Your appointment at this drive is cancelled. You do not need to do anything.')
            ->action('Book another appointment', $frontend.'/donor/appointments')
            ->line('Questions? Call us on '.config('donation.support.hotline_label').' ('.config('donation.support.hours').').');
    }

    /**
     * Build the in-app copy the donor's notifications screen lists.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            // One of DonorNotificationService::CATEGORIES.
            'category' => 'reminder',
            'title' => 'Blood drive cancelled: '.$this->drive->name,
            'desc' => $this->longDate().' at '.$this->drive->location.'. Your appointment there is cancelled.'
                .(filled($this->drive->cancellation_reason) ? ' Reason: '.$this->drive->cancellation_reason : ''),
            'meta' => 'By '.$this->centre(),
            'icon' => 'circle-x',
            'tone' => 'warning',
            'action_label' => 'Book another',
            'action_route' => '/donor/appointments',
            'mobile_event_id' => $this->drive->id,
        ];
    }

    private function centre(): string
    {
        return $this->drive->facility?->name ?? 'Your blood centre';
    }

    private function longDate(): string
    {
        return $this->drive->event_date->format('l, j F Y');
    }
}
