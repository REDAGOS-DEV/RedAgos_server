<?php

namespace App\Notifications;

use App\Enums\ScreeningOutcome;
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
        private readonly ?string $reason = null,
        private readonly ?ScreeningOutcome $outcome = null
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

        // A donor who may never donate again must not be told a deferral is
        // usually temporary, and must not be handed a button to book another
        // appointment. What they get instead is the hotline and a person.
        if ($this->isBlocking()) {
            return $message
                ->line('Please speak to our staff before booking again. They can explain what was recorded and what it means for you.')
                ->line('Call us on '.config('donation.support.hotline_label').' ('.config('donation.support.hours').').');
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
        $blocking = $this->isBlocking();

        return [
            'category' => 'screening',
            'title' => 'You were deferred today',
            'desc' => $this->reason
                ? $this->reason
                : 'You were not able to donate at '.$this->venue().' today.',
            'meta' => $blocking
                ? 'Please speak to our staff before booking again.'
                : 'Deferrals are usually temporary.',
            'icon' => 'alert-circle',
            'tone' => 'warning',
            // No rebooking action on a blocking deferral. The card is read
            // days later with none of the counter's context around it, so the
            // button is the part most likely to be acted on alone.
            'action_label' => $blocking ? null : 'Book again',
            'action_route' => $blocking ? null : '/donor/appointments',
        ];
    }

    /**
     * Determine whether this deferral has no expected end.
     *
     * Defaults to false when no outcome was passed, which keeps the wording
     * for every deferral recorded before the form's four REMARKS boxes existed
     * exactly as those donors were originally told.
     */
    private function isBlocking(): bool
    {
        return $this->outcome?->isBlocking() ?? false;
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
