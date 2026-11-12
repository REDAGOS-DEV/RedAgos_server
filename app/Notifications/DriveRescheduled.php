<?php

namespace App\Notifications;

use App\Models\DonationAppointment;
use App\Models\MobileEvent;
use App\Support\DonorNotificationPreferences;
use App\Support\SmsName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A drive the donor is booked for has moved, and their booking with it
 * (project owner, 2026-11-12: "a booked drive should be movable with the
 * donors notified"). Sent by MobileEventService::update() after the move
 * commits.
 *
 * On the same channels as the booking confirmation (AppointmentScheduled):
 * the in-app copy is always written, and the email and text follow the
 * donor's appointment switch. Queued; see DonationRecorded.
 */
class DriveRescheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly MobileEvent $drive,
        private readonly DonationAppointment $appointment
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return DonorNotificationPreferences::alwaysInAppChannels($notifiable, DonorNotificationPreferences::APPOINTMENTS_EMAIL, sms: true);
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
     * Build the notice addressed to the SPA.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject('Blood drive moved: '.$this->drive->name)
            ->greeting('This blood drive has moved')
            ->line($this->centre().' has moved '.$this->drive->name.'.')
            ->line('**When:** '.$this->longDate().', '.$this->hours())
            ->line('**Where:** '.$this->drive->location)
            ->line('Your appointment moves with it, to '.$this->longDate().' at '.$this->time().'. You do not need to book again.')
            ->action('View my appointment', $frontend.'/donor/appointments')
            ->line('If you can no longer come, you can cancel from your appointments screen.');
    }

    /**
     * Build the text message, one SMS.
     */
    public function toSms(object $notifiable): string
    {
        // Named: a household may share one phone (2026-11-12).
        return SmsName::open($notifiable->first_name ?? null, $this->centre().' has moved ').$this->drive->name
            .' to '.$this->drive->event_date->format('j M Y').'. Your appointment is now '.$this->time()
            .' that day. See the RedAgos portal.';
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
            'title' => 'Blood drive moved: '.$this->drive->name,
            'desc' => 'Now '.$this->longDate().', '.$this->hours().' at '.$this->drive->location.'. Your appointment is at '.$this->time().'.',
            'meta' => 'By '.$this->centre(),
            'icon' => 'calendar',
            'tone' => 'info',
            'action_label' => 'View appointment',
            'action_route' => '/donor/appointments',
            'mobile_event_id' => $this->drive->id,
            'appointment_id' => $this->appointment->id,
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

    private function time(): string
    {
        return $this->appointment->appointment_datetime->format('g:i A');
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
