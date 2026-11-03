<?php

namespace App\Rules;

use App\Enums\ValidIdType;
use App\Support\AccountIdentity;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An ID number in the shape its ID type prints it: a PhilSys number is 16
 * digits, a driver's license 1 letter and 10 digits, and so on
 * (ValidIdType::numberPattern()).
 *
 * Spaces, hyphens and case are dropped before the check, as they are before the
 * number is stored, so "1234-5678-9012-3456" passes as a PhilSys number. An ID
 * type with no fixed numbering (Postal, PRC, Voter's ID), an unknown type —
 * which the type's own rule reports — or no type at all leaves the number as
 * printed.
 */
class ValidIdNumber implements ValidationRule
{
    public function __construct(private readonly mixed $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = is_string($this->type) ? ValidIdType::tryFrom($this->type) : null;
        $pattern = $type?->numberPattern();

        if ($pattern === null) {
            return;
        }

        $number = is_string($value) ? AccountIdentity::normalizeValidIdNumber($value) : null;

        if ($number === null || preg_match($pattern, $number) !== 1) {
            $fail($type->numberFormat().'.');
        }
    }
}
