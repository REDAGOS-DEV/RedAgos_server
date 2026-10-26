<?php

namespace App\Notifications;

use App\Enums\ScreeningOutcome;
use App\Models\Donation;
use App\Support\DonorNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Tell a donor deferred at the counter that they were, under which code, and
 * that they are welcome back.
 *
 * The deferral code, never the reason (project owner, 2026-10-24): the reason
 * is confidential, and an email or a phone screen is read by more than the
 * donor. The code is something to quote when they ask the centre, whose staff
 * explain it in person. Deferrals recorded before codes existed carry no code,
 * and their notice simply says the donor could not donate.
 *
 * Queued, because it is sent at the end of a request that has already
 * committed. An SMTP server that hangs would otherwise hold that request until
 * PHP's time limit, a fatal error no try/catch answers, and staff would see
 * "save failed" for a record that was saved.
 */
class DonorDeferred extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string|null  $until  A temporary deferral's last day (Y-m-d), set at the counter.
     */
    public function __construct(
        private readonly Donation $donation,
        private readonly ?string $code = null,
        private readonly ?ScreeningOutcome $outcome = null,
        private readonly ?string $until = null
    ) {}

    /**
     * Mailed and stored, dropping mail for a counter-registered donor with no address.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // The email is the donor's choice in Settings; the in-app copy is always written.
        return DonorNotificationPreferences::alwaysInAppChannels($notifiable, DonorNotificationPreferences::RESULTS_EMAIL);
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
     * Build the deferral notice addressed to the SPA.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $message = (new MailMessage)
            ->subject('About your visit on '.$this->shortDate())
            ->greeting('Thank you for coming in, '.$notifiable->first_name.'.')
            ->line('You were not able to donate at '.$this->venue().' today.');

        if ($this->code) {
            $message->line('**Deferral code:** '.$this->code.'. Our staff can tell you what it means.');
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
            ->line($this->until
                ? 'A deferral is common and is usually temporary. Yours lasts until '.$this->untilLabel().'; you can book again after that date.'
                : 'A deferral is common and is usually temporary. Our staff can tell you when it would be right to try again.')
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
            'desc' => 'You were not able to donate at '.$this->venue().' today.'
                .($this->code ? ' Deferral code: '.$this->code.'.' : ''),
            'meta' => match (true) {
                $blocking => 'Please speak to our staff before booking again.',
                $this->until !== null => 'Deferred until '.$this->untilLabel().'.',
                default => 'Deferrals are usually temporary.',
            },
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

    private function untilLabel(): string
    {
        return Carbon::parse((string) $this->until)->format('j M Y');
    }

    private function shortDate(): string
    {
        return $this->donation->donation_date->format('j M Y');
    }
}
