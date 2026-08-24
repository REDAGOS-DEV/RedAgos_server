<?php

namespace App\Notifications;

use App\Enums\FacilityStatus;
use App\Models\Facility;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FacilityRegistrationDecision extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Facility $facility,
        private readonly FacilityStatus $decision,
        private readonly ?string $reason = null
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the decision email addressed to the SPA.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return match ($this->decision) {
            FacilityStatus::Approved => (new MailMessage)
                ->subject('Your RedAgos blood center registration is approved')
                ->greeting('Good news!')
                ->line($this->facility->name.' has been approved.')
                ->line('You can now sign in and begin managing your inventory.')
                ->action('Sign in', $frontend.'/auth/blood-center/login')
                ->line('If you have not yet confirmed your email address, please do so before using the system.'),

            FacilityStatus::Rejected => (new MailMessage)
                ->subject('Your RedAgos blood center registration was not approved')
                ->greeting('Registration update')
                ->line('The registration for '.$this->facility->name.' was not approved.')
                ->line($this->reason ? 'Reason: '.$this->reason : 'No reason was recorded.')
                ->line('You can correct the details and submit the registration again.')
                ->action('Review your registration', $frontend.'/auth/blood-center/registration-status'),

            FacilityStatus::Suspended => (new MailMessage)
                ->subject('Your RedAgos blood center account has been suspended')
                ->greeting('Account suspended')
                ->line($this->facility->name.' has been suspended and can no longer act on inventory.')
                ->line($this->reason ? 'Reason: '.$this->reason : 'No reason was recorded.')
                ->line('Please contact the administrator if you believe this is a mistake.'),

            FacilityStatus::PendingApproval => (new MailMessage)
                ->subject('Your RedAgos blood center registration is under review')
                ->greeting('Registration received')
                ->line('The registration for '.$this->facility->name.' is being reviewed.')
                ->line('We will email you once a decision has been made.'),
        };
    }
}
