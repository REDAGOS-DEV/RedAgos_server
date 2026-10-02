<?php

namespace App\Notifications;

use App\Models\Donation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Ask a donor whose serology came back reactive to contact the blood centre.
 *
 * WHAT IT MUST NEVER SAY. Section I-C of the DOH form tells the donor that
 * their blood will be tested and "no official result will be issued to me".
 * So this names no result, no test, no marker and no infection, and does not
 * say the donor was deferred — telling someone that in an app notification,
 * with nobody to talk to, is exactly what counselling exists to avoid. It asks
 * them to get in touch; the Testing department's referral list is where staff
 * follow up if they do not.
 *
 * DonorDeferred is not reused, because it prints the recorded reason.
 *
 * In-app only, by the project owner's decision: an email can be read by
 * whoever shares the inbox. No rebooking action, because this donor may never
 * donate again.
 */
class DonorContactRequested extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Donation $donation
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Build the in-app copy the donor's notifications screen lists.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => 'system',
            'title' => 'Please contact your blood centre',
            'desc' => 'Our staff would like to speak with you about your donation at '.$this->venue().' on '.$this->shortDate().'.',
            'meta' => 'Call us on '.config('donation.support.hotline_label').' ('.config('donation.support.hours').').',
            'icon' => 'phone',
            'tone' => 'info',
            'action_label' => null,
            'action_route' => null,
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
