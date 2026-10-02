<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued, so a mail server that hangs never holds up the request that sent it
 * (see DonationRecorded), and encrypted on the queue because the payload holds
 * the plaintext reset token, which the database otherwise only keeps hashed.
 */
class ResetPasswordNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /**
     * Get the delivery channels for this notification.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the password reset email addressed to the SPA.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $expiresInMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Reset your RedAgos password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $this->resetUrl($notifiable))
            ->line("This password reset link expires in {$expiresInMinutes} minutes.")
            ->line('If you did not request a password reset, no further action is required.');
    }

    /**
     * Build the frontend URL that carries the reset token and email address.
     */
    protected function resetUrl(object $notifiable): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .'/auth/reset-password?'
            .http_build_query([
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
    }
}
