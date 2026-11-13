<?php

namespace Tests\Unit;

use App\Service\SemaphoreSms;
use App\Support\ReservedContacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

/**
 * Contacts that reach no one: demo people carry them, and RedAgos sends them
 * nothing (project owner, 2026-11-13).
 */
class ReservedContactsTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function addresses(): array
    {
        return [
            'example.com' => ['donor.snbc.o-pos.1@example.com', true],
            'example.org' => ['a@example.org', true],
            'a subdomain of example.net' => ['a@mail.example.net', true],
            'capitals' => ['A@EXAMPLE.COM', true],
            '.test' => ['billing.clerk@redagos.test', true],
            '.invalid' => ['x@nowhere.invalid', true],
            'gmail' => ['someone@gmail.com', false],
            'a look-alike' => ['x@notexample.com', false],
            'a real centre' => ['info@redagos.org', false],
            'no address' => ['', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_example_domains_are_reserved(string $address, bool $reserved): void
    {
        $this->assertSame($reserved, ReservedContacts::isReservedEmail($address));
    }

    public function test_the_demo_block_is_plus_63_900(): void
    {
        $this->assertSame('+639000000001', ReservedContacts::demoMobile(1));
        $this->assertSame('+639001200045', ReservedContacts::demoMobile(1_200_045));

        foreach (['+639000000001', '639001234567', '09001234567', '0900 123 4567'] as $demo) {
            $this->assertTrue(ReservedContacts::isDemoMobile($demo), $demo);
        }

        foreach (['+639171234567', '09981234567', '+63900123456', null, ''] as $real) {
            $this->assertFalse(ReservedContacts::isDemoMobile($real), (string) $real);
        }
    }

    public function test_a_demo_number_is_never_texted(): void
    {
        $this->assertNull(SemaphoreSms::recipient('+639000000001'));
        $this->assertNull(SemaphoreSms::recipient('09001234567'));

        // A real mobile still is.
        $this->assertSame('639171234567', SemaphoreSms::recipient('+639171234567'));
    }

    public function test_a_mail_to_no_one_real_is_not_sent(): void
    {
        $message = (new Email)->to('donor.snbc.o-pos.1@example.com')->text('x');

        $this->assertFalse(ReservedContacts::screen($message));
    }

    public function test_reserved_addresses_are_taken_off_a_mail_that_has_real_ones(): void
    {
        $message = (new Email)
            ->to('donor@example.com', 'someone@gmail.com')
            ->cc('clerk@redagos.test')
            ->bcc('audit@redagos.org')
            ->text('x');

        $this->assertTrue(ReservedContacts::screen($message));
        $this->assertSame(['someone@gmail.com'], array_map(fn ($a) => $a->getAddress(), $message->getTo()));
        $this->assertSame([], $message->getCc());
        $this->assertSame(['audit@redagos.org'], array_map(fn ($a) => $a->getAddress(), $message->getBcc()));
    }
}
