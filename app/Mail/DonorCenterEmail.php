<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An email a blood centre sends one of its donors: a thank-you, or a reminder
 * that they may give again.
 *
 * Sent as the centre, not as RedAgos. The From name is the centre's and a reply
 * goes to the centre's own address; the page carries the centre's name and
 * contact details and no RedAgos branding. The From *address* stays the
 * application's, because the mail server only relays for the account it
 * authenticates — sending as the centre's own address would be spoofing it,
 * and would be filed as spam or refused.
 *
 * Built from plain strings rather than models, so what is queued is exactly
 * what will be sent: an edit to the donor or the template after the click
 * does not change an email already on its way.
 *
 * Queued, like DonationRecorded, so an SMTP server that hangs cannot hold a
 * reminder run of hundreds of donors until PHP's time limit.
 */
class DonorCenterEmail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $paragraphs  plain text, escaped by the views
     */
    public function __construct(
        public readonly string $centerName,
        public readonly ?string $centerEmail,
        public readonly ?string $centerPhone,
        public readonly ?string $centerAddress,
        public readonly string $subjectLine,
        public readonly array $paragraphs,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->centerName),
            replyTo: $this->centerEmail ? [new Address($this->centerEmail, $this->centerName)] : [],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.donor-center-email',
            text: 'mail.donor-center-email-text',
        );
    }
}
