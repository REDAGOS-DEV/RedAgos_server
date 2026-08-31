<?php

namespace App\Notifications;

use App\Enums\IdentityStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DonorIdentityDecision extends Notification
{
    use Queueable;

    public function __construct(
        private readonly IdentityStatus $decision,
        private readonly ?string $reason = null
    ) {}

    /**
     * Mailed and stored: the donor reads the decision in their inbox, and the
     * database copy is what the in-app notifications screen lists.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Build the decision email addressed to the SPA.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return match ($this->decision) {
            IdentityStatus::Verified => (new MailMessage)
                ->subject('Your RedAgos ID has been verified')
                ->greeting('Good news!')
                ->line('The ID you submitted has been verified.')
                ->line('Check-in at the donation counter will be quicker from now on.')
                ->action('View your profile', $frontend.'/donor/profile'),

            IdentityStatus::Rejected => (new MailMessage)
                ->subject('Your RedAgos ID was not approved')
                ->greeting('ID verification update')
                ->line('The ID you submitted could not be verified.')
                ->line($this->reason ? 'Reason: '.$this->reason : 'No reason was recorded.')
                ->line('You can submit a clearer photo from your profile at any time.')
                ->action('Submit another ID', $frontend.'/donor/profile'),

            IdentityStatus::Pending, IdentityStatus::Unsubmitted => (new MailMessage)
                ->subject('Your RedAgos ID is under review')
                ->greeting('ID received')
                ->line('The ID you submitted is being reviewed.')
                ->line('We will email you once a decision has been made.'),
        };
    }

    /**
     * Build the in-app copy the donor's notifications screen lists.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $verified = $this->decision === IdentityStatus::Verified;

        return [
            // Keys match what DonorNotificationService::format() reads; category
            // is one of DonorNotificationService::CATEGORIES, which is what the
            // notifications screen filters by.
            'category' => 'system',
            'title' => $verified ? 'ID verified' : 'ID not approved',
            'desc' => $verified
                ? 'The ID you submitted has been verified.'
                : 'The ID you submitted could not be verified.'.($this->reason ? ' Reason: '.$this->reason : ''),
            'icon' => 'user-circle',
            'tone' => $verified ? 'success' : 'warning',
            'action_label' => $verified ? 'View profile' : 'Submit another ID',
            'action_route' => '/donor/profile',
        ];
    }
}
