<?php

namespace App\Notifications;

use App\Models\DonationAppointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentScheduled extends Notification
{
    use Queueable;

    public function __construct(
        private readonly DonationAppointment $appointment
    ) {}

    /**
     * Mailed and stored: the donor keeps the details in their inbox, and the
     * database copy is what the in-app notifications screen lists.
     *
     * A donor registered at the counter may hold no address, so the mail
     * channel is dropped for them rather than failing at the mailer. The
     * in-app copy is still written, which is the only one they can read.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->hasEmailAddress()
            ? ['mail', 'database']
            : ['database'];
    }

    /**
     * Build the booking confirmation addressed to the SPA.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $windowHours = (int) config('donation.cancellation_window_hours');

        return (new MailMessage)
            ->subject('Your RedAgos appointment on '.$this->shortDate())
            ->greeting('Your appointment is booked!')
            ->line('Here are the details of the donation appointment you just scheduled.')
            ->line('**When:** '.$this->longDate().' at '.$this->time())
            ->line('**Where:** '.$this->venue())
            ->line('**Reference:** #'.$this->appointment->id)
            ->action('View my appointment', $frontend.'/donor/appointments')
            ->line('Please bring a valid ID and your donor QR code. Eat a full meal and drink plenty of water before you arrive.')
            ->line("You can reschedule or cancel from your appointments screen up to {$windowHours} hours beforehand.")
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
            // Keys match what DonorNotificationService::format() reads; category
            // is one of DonorNotificationService::CATEGORIES, which is what the
            // notifications screen filters by.
            'category' => 'reminder',
            'title' => 'Appointment booked',
            'desc' => $this->longDate().' at '.$this->time().' — '.$this->venue().'.',
            'meta' => 'Reference #'.$this->appointment->id,
            'icon' => 'calendar-check',
            'tone' => 'success',
            'action_label' => 'View appointment',
            'action_route' => '/donor/appointments',
        ];
    }

    /**
     * Where the donor is expected: a mobile drive names its own venue, a walk-in
     * booking names the centre it was made against.
     */
    private function venue(): string
    {
        $drive = $this->appointment->mobileEvent;

        if ($drive) {
            return $drive->name.', '.$drive->location;
        }

        $facility = $this->appointment->facility;

        return trim(($facility?->name ?? 'RedAgos blood centre').', '.($facility?->address ?? ''), ', ');
    }

    private function shortDate(): string
    {
        return $this->appointment->appointment_datetime->format('j M Y');
    }

    private function longDate(): string
    {
        return $this->appointment->appointment_datetime->format('l, j F Y');
    }

    private function time(): string
    {
        return $this->appointment->appointment_datetime->format('g:i A');
    }
}
