<?php

namespace App\Notifications;

use App\Models\Donation;
use App\Support\DonorNotificationPreferences;
use App\Support\SmsName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tell the donor that blood they gave has been given to a patient (project owner, 2026-11-26).
 *
 * Sent once per donation, when a hospital records the first transfusion of
 * any of its bags (HospitalInventoryService::transfuse()). It names the
 * donation, by its date and the centre it was given at, and nothing about
 * the patient: not who, not where, not when. In a city the size of Davao the
 * hospital and the day could point to the person.
 *
 * Queued like the other donor notices, so a hanging mail server never holds
 * the hospital's request.
 */
class DonatedBloodTransfused extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Donation $donation
    ) {}

    /**
     * The in-app copy is always written; the email and the text follow the
     * donor's "Blood transfused" switch in Settings.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return DonorNotificationPreferences::alwaysInAppChannels($notifiable, DonorNotificationPreferences::TRANSFUSION_EMAIL, sms: true);
    }

    /**
     * Only the mail and the text wait for the queue worker; the in-app copy is written at once.
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
            ->subject('Your blood has helped a patient')
            ->greeting('Thank you, '.$notifiable->first_name.'!')
            ->line('Blood you donated on '.$this->longDate().' at '.$this->venue().' has been given to a patient who needed it.')
            ->line('To protect the patient\'s privacy, we cannot say who received it or where.')
            ->line('**Reference:** #'.$this->donation->id)
            ->action('View my donation history', $frontend.'/donor/history')
            ->line('Thank you for giving.');
    }

    /**
     * Plain ASCII and one SMS part, like the other donor texts.
     */
    public function toSms(object $notifiable): string
    {
        return SmsName::open(
            $notifiable->first_name ?? null,
            'blood you donated on '.$this->shortDate().' has been given to a patient who needed it. Thank you for giving!'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => 'donation',
            'title' => 'Your blood helped a patient',
            'desc' => 'Blood you donated on '.$this->shortDate().' at '.$this->venue().' has been given to a patient who needed it.',
            'meta' => 'Reference #'.$this->donation->id,
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
