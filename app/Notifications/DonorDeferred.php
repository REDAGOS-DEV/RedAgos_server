<?php

namespace App\Notifications;

use App\Models\Donation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tell a donor deferred at the counter why, and that they are welcome back.
 *
 * The reason is the whole point: HelpPage already promises the donor can
 * "check the reason shown after your screening", and a deferral with no reason
 * is the thing most likely to stop someone returning. The text is the one an
 * authorized member of staff recorded — nothing here composes a clinical
 * explanation of its own.
 */
class DonorDeferred extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Donation $donation,
        private readonly ?string $reason = null
    ) {}

    /**
     * Mailed and stored, dropping mail for a counter-registered donor with no address.
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
     * Build the deferral notice addressed to the SPA.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $message = (new MailMessage)
            ->subject('About your visit on '.$this->shortDate())
            ->greeting('Thank you for coming in, '.$notifiable->first_name.'.')
            ->line('You were not able to donate at '.$this->venue().' today.');

        if ($this->reason) {
            $message->line('**Reason recorded:** '.$this->reason);
        }

        return $message
            ->line('A deferral is common and is usually temporary. Our staff can tell you when it would be right to try again.')
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
            'category' => 'screening',
            'title' => 'You were deferred today',
            'desc' => $this->reason
                ? $this->reason
                : 'You were not able to donate at '.$this->venue().' today.',
            'meta' => 'Deferrals are usually temporary.',
            'icon' => 'alert-circle',
            'tone' => 'warning',
            'action_label' => 'Book again',
            'action_route' => '/donor/appointments',
        ];
    }

    private function venue(): string
    {
        return $this->donation->facility?->name ?? 'a RedAgos blood centre';
    }

    private function shortDate(): string
    {
        return $this->donation->donation_date->format('j M Y');
    }
}
