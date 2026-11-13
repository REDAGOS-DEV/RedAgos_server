<?php

namespace Tests\Feature;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\TestCase;

/**
 * Demo people carry example.com addresses, and RedAgos sends them nothing
 * (project owner, 2026-11-13): the mailer drops them before the transport.
 *
 * Against the array transport phpunit.xml configures, which keeps every
 * message it is handed, so "not sent" is "never reached the transport".
 */
class ReservedContactsMailTest extends TestCase
{
    private function transport(): TransportInterface
    {
        return Mail::mailer()->getSymfonyTransport();
    }

    public function test_mail_to_an_example_address_never_reaches_the_transport(): void
    {
        Mail::raw('Your donation was recorded.', fn (Message $m) => $m->to('donor.snbc.o-pos.1@example.com'));

        $this->assertCount(0, $this->transport()->messages());
    }

    public function test_mail_to_a_real_address_still_goes(): void
    {
        Mail::raw('Your donation was recorded.', fn (Message $m) => $m->to('someone@gmail.com')->cc('clerk@example.com'));

        $sent = $this->transport()->messages();

        $this->assertCount(1, $sent);
        $this->assertSame(['someone@gmail.com'], array_map(fn ($a) => $a->getAddress(), $sent[0]->getOriginalMessage()->getTo()));
        $this->assertSame([], $sent[0]->getOriginalMessage()->getCc());
    }
}
