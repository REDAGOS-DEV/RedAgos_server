<?php

namespace App\Notifications;

use App\Models\EmailVerificationCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The verification code, mailed (2026-11-03). The donor or staff member types
 * it into the portal; there is no link to click any more.
 *
 * Queued, so a mail server that hangs never holds up the request that sent it
 * (see DonationRecorded), and encrypted on the queue because the payload holds
 * the plaintext code, which the database otherwise only keeps hashed.
 */
class VerifyEmailNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $code) {}

    /**
     * Get the delivery channels for this notification.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your RedAgos verification code')
            ->markdown('mail.verification-code', [
                'code' => $this->code,
                'minutes' => EmailVerificationCode::lifetimeMinutes(),
            ]);
    }
}
