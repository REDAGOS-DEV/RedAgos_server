<?php

namespace App\Notifications;

use App\Models\DonationAppointment;
use App\Support\AppointmentScreeningWindow;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tell a donor their health questionnaire is due.
 *
 * This is load-bearing rather than a courtesy. Booking no longer requires a
 * questionnaire, so nothing else stands between a donor booking and arriving
 * with no QR code. It goes out twice: when the window opens the day before,
 * and again on the morning of the appointment if they still have not answered.
 * The second one still lands while they can act on it, because the window stays
 * open all day.
 *
 * One class rather than two, because the two differ only in wording -- and a
 * second class would be a second thing to keep in step with the first.
 */
class ScreeningWindowOpen extends Notification
{
    use Queueable;

    public function __construct(
        private readonly DonationAppointment $appointment,
        private readonly bool $isFinalReminder = false
    ) {}

    /**
     * Mailed and stored, following AppointmentScheduled.
     *
     * A donor registered at the counter may hold no address, so the mail
     * channel is dropped for them rather than failing at the mailer. In
     * practice a donor with an appointment has a verified address, since
     * booking requires one -- the guard is here for symmetry with every other
     * donor notification rather than because this path can reach it.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->hasEmailAddress()
            ? ['mail', 'database']
            : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $message = (new MailMessage)
            ->subject($this->isFinalReminder
                ? 'Your donation is today - please complete your health questionnaire'
                : 'Your health questionnaire is now open')
            ->greeting($this->isFinalReminder ? 'Your appointment is today' : 'Your appointment is tomorrow');

        if ($this->isFinalReminder) {
            $message->line('You have not yet completed your health questionnaire, and it is needed before you can check in.');
        } else {
            $message->line('Your health questionnaire is now open. Completing it today means your visit tomorrow starts with a scan rather than a form.');
        }

        return $message
            ->line('**When:** '.$this->longDate().' at '.$this->time())
            ->line('**Where:** '.$this->venue())
            ->action('Complete my questionnaire', $frontend.'/donor/eligibility')
            ->line('It takes a few minutes, and your QR code is issued as soon as you submit it.')
            ->line('If you have not completed it by the time you arrive, you can still fill it in at the centre.')
            ->line('Questions? Call us on '.config('donation.support.hotline_label').' ('.config('donation.support.hours').').');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            // 'screening' is already one of DonorNotificationService::CATEGORIES,
            // so the donor's notifications screen filters this with no change.
            'category' => 'screening',
            'title' => $this->isFinalReminder
                ? 'Questionnaire still needed for today'
                : 'Your health questionnaire is open',
            'desc' => 'For your appointment on '.$this->longDate().' at '.$this->time().'.',
            'meta' => 'Closes at the end of '.$this->closesOn(),
            'icon' => 'clipboard-list',
            'tone' => $this->isFinalReminder ? 'warning' : 'info',
            'action_label' => 'Complete questionnaire',
            'action_route' => '/donor/eligibility',
            // Read back by the command to decide whether this donor has already
            // been told, so a re-run of the cron cannot send twice.
            'appointment_id' => $this->appointment->id,
            'stage' => $this->isFinalReminder ? 'final' : 'opened',
        ];
    }

    private function venue(): string
    {
        $drive = $this->appointment->mobileEvent;

        if ($drive) {
            return $drive->name.', '.$drive->location;
        }

        $facility = $this->appointment->facility;

        return trim(($facility?->name ?? 'RedAgos blood centre').', '.($facility?->address ?? ''), ', ');
    }

    private function longDate(): string
    {
        return $this->appointment->appointment_datetime->format('l, j F Y');
    }

    private function time(): string
    {
        return $this->appointment->appointment_datetime->format('g:i A');
    }

    private function closesOn(): string
    {
        return AppointmentScreeningWindow::closesAt($this->appointment)->format('j F');
    }
}
