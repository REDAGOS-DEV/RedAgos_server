<?php

namespace App\Notifications;

use App\Models\Donation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Tell the donor their donation was recorded, and when they may give again.
 *
 * Deliberately says nothing about testing. The bag has been collected, not
 * cleared for issue — that is a separate laboratory decision, and a donor told
 * "your donation is complete" would reasonably read it as a clean bill of
 * health the centre has not yet established.
 */
class DonationRecorded extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Donation $donation,
        private readonly ?Carbon $nextEligibleDate = null
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
     * Build the thank-you addressed to the SPA.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $message = (new MailMessage)
            ->subject('Thank you for donating on '.$this->shortDate())
            ->greeting('Thank you, '.$notifiable->first_name.'!')
            ->line('Your donation at '.$this->venue().' has been recorded.')
            ->line('**Date:** '.$this->longDate())
            ->line('**Volume:** '.($this->donation->volume_ml ?? '—').' mL')
            ->line('**Reference:** #'.$this->donation->id);

        if ($this->nextEligibleDate) {
            $message->line('**You can donate again from:** '.$this->nextEligibleDate->format('j F Y'));
        }

        return $message
            ->action('View my donation history', $frontend.'/donor/history')
            ->line('Rest, keep drinking fluids, and avoid heavy lifting for the next few hours.')
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
            'category' => 'donation',
            'title' => 'Donation recorded',
            'desc' => ($this->donation->volume_ml ?? '—').' mL recorded at '.$this->venue().'.',
            'meta' => $this->nextEligibleDate
                ? 'You can donate again from '.$this->nextEligibleDate->format('j M Y')
                : 'Reference #'.$this->donation->id,
            'icon' => 'droplets',
            'tone' => 'success',
            'action_label' => 'View history',
            'action_route' => '/donor/history',
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

    private function longDate(): string
    {
        return $this->donation->donation_date->format('l, j F Y');
    }
}
