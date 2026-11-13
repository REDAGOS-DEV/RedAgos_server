<?php

namespace App\Support;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Email addresses and mobile numbers that belong to no one, so demo people
 * can carry them and nothing sent to them leaves RedAgos (project owner,
 * 2026-11-13: "no real email or SMS goes out").
 *
 * Email: the domains set aside for examples by RFC 2606 and RFC 6761. They
 * accept no mail, but a mail server would still try and bounce it back to
 * the sender, so RedAgos does not send at all.
 *
 * SMS: the Philippines has no numbering set aside for examples, so RedAgos
 * keeps one: +63 900 xxx xxxx. No network gives out 0900 numbers, and even
 * if one ever did, RedAgos never texts them.
 */
final class ReservedContacts
{
    /**
     * @var array<int, string>
     */
    private const DOMAINS = ['example.com', 'example.net', 'example.org'];

    /**
     * @var array<int, string>
     */
    private const TOP_LEVEL = ['test', 'example', 'invalid', 'localhost'];

    /**
     * The demo mobile block, as Semaphore writes a number: 63900 then seven digits.
     */
    private const DEMO_MOBILE = '/^63900\d{7}$/';

    /**
     * Whether mail to this address would reach no one.
     */
    public static function isReservedEmail(?string $address): bool
    {
        $at = strrpos((string) $address, '@');

        if ($at === false) {
            return false;
        }

        $domain = strtolower(rtrim(substr((string) $address, $at + 1), '.'));
        $labels = explode('.', $domain);

        return in_array($domain, self::DOMAINS, true)
            || self::endsWithAny($domain, self::DOMAINS)
            || in_array(end($labels), self::TOP_LEVEL, true);
    }

    /**
     * Whether a number is in the demo block, in any of the forms users.phone holds.
     */
    public static function isDemoMobile(?string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '63'.substr($digits, 1);
        }

        return preg_match(self::DEMO_MOBILE, $digits) === 1;
    }

    /**
     * The n-th demo mobile number, as users.phone stores it: +639000000001.
     */
    public static function demoMobile(int $n): string
    {
        return '+63900'.str_pad((string) $n, 7, '0', STR_PAD_LEFT);
    }

    /**
     * Take reserved addresses off an outgoing mail.
     *
     * Returns false when nobody real is left to send it to, which cancels the
     * send (Laravel's MessageSending: a listener returning false stops it).
     */
    public static function screen(Email $message): bool
    {
        $lists = [
            'To' => $message->getTo(),
            'Cc' => $message->getCc(),
            'Bcc' => $message->getBcc(),
        ];

        $kept = array_map(fn (array $addresses): array => array_values(array_filter(
            $addresses,
            fn (Address $address): bool => ! self::isReservedEmail($address->getAddress())
        )), $lists);

        if (array_merge(...array_values($kept)) === []) {
            return false;
        }

        foreach ($kept as $header => $addresses) {
            if (count($addresses) === count($lists[$header])) {
                continue;
            }

            $addresses === []
                ? $message->getHeaders()->remove($header)
                : $message->getHeaders()->get($header)?->setAddresses($addresses);
        }

        return true;
    }

    /**
     * @param  array<int, string>  $domains
     */
    private static function endsWithAny(string $domain, array $domains): bool
    {
        foreach ($domains as $reserved) {
            if (str_ends_with($domain, '.'.$reserved)) {
                return true;
            }
        }

        return false;
    }
}
