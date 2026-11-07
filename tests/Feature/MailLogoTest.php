<?php

namespace Tests\Feature;

use App\Mail\DonorCenterEmail;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * RedAgos emails open with the logo (2026-11-06).
 *
 * Linked, not attached: Gmail lists an attached image as a chip in the inbox.
 * What is locked in: the header shows the configured address with nothing
 * attached, and a centre's own email, which has its own header, shows no logo.
 */
class MailLogoTest extends TestCase
{
    private const LOGO = 'https://portal.example.test/images/mail-logo.png';

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.logo_url' => self::LOGO]);
    }

    private function lastSent(): Email
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }

    public function test_the_logo_is_linked_and_nothing_is_attached(): void
    {
        Notification::route('mail', 'donor@example.com')->notifyNow(new VerifyEmailNotification('123456'));

        $email = $this->lastSent();

        $this->assertStringContainsString('src="'.self::LOGO.'"', (string) $email->getHtmlBody());
        $this->assertSame([], $email->getAttachments());
    }

    public function test_the_logo_links_to_the_portal_not_the_api(): void
    {
        config(['app.frontend_url' => 'https://portal.example.test', 'app.url' => 'https://api.example.test']);

        Notification::route('mail', 'donor@example.com')->notifyNow(new VerifyEmailNotification('123456'));

        $html = (string) $this->lastSent()->getHtmlBody();

        $this->assertStringContainsString('href="https://portal.example.test"', $html);
        $this->assertStringNotContainsString('href="https://api.example.test"', $html);
    }

    public function test_a_centres_own_email_shows_no_logo(): void
    {
        Mail::to('donor@example.com')->sendNow(new DonorCenterEmail(
            'SNBC', 'snbc@example.com', null, null, 'Thank you', ['Thank you for donating.'],
        ));

        $this->assertStringNotContainsString(self::LOGO, (string) $this->lastSent()->getHtmlBody());
    }
}
