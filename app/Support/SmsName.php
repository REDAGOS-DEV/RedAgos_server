<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The first name a donor text opens with (project owner, 2026-11-12).
 *
 * A household may share one mobile number, so every text says whose it is.
 * Written in plain letters (Niño → Nino): one character outside the GSM set
 * turns the whole message into Unicode, which Semaphore bills at 70
 * characters a part instead of 160.
 */
final class SmsName
{
    /** The donor's first name in plain letters, or '' when there is none. */
    public static function of(?string $firstName): string
    {
        $name = trim(preg_replace('/[^\x20-\x7E]/', '', Str::ascii((string) $firstName)) ?? '');

        return preg_replace('/\s+/', ' ', $name) ?? '';
    }

    /**
     * "RedAgos: Ana, " followed by the message, or "RedAgos: " with its first
     * letter capitalised when there is no name.
     */
    public static function open(?string $firstName, string $message): string
    {
        $name = self::of($firstName);

        return $name === ''
            ? 'RedAgos: '.ucfirst($message)
            : 'RedAgos: '.$name.', '.$message;
    }
}
