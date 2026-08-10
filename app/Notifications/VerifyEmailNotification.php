<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class VerifyEmailNotification extends Notification
{
    use Queueable;

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
     * Build the verification email addressed to the SPA.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your RedAgos email address')
            ->greeting('Welcome to RedAgos!')
            ->line('Please confirm your email address to unlock appointment booking and your donor QR code.')
            ->action('Verify Email Address', $this->verificationUrl($notifiable))
            ->line('This link expires in '.config('auth.verification.expire', 60).' minutes.')
            ->line('If you did not create a RedAgos account, no further action is required.');
    }

    /**
     * Build a frontend URL carrying the signed parameters the API will validate.
     *
     * The query string is forwarded verbatim because Laravel validates the
     * signature against the raw, order-sensitive query string of the request.
     */
    protected function verificationUrl(MustVerifyEmail $notifiable): string
    {
        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
            absolute: true
        );

        return rtrim((string) config('app.frontend_url'), '/')
            .'/auth/verify-email?'
            .Str::after($signedUrl, '?');
    }
}
