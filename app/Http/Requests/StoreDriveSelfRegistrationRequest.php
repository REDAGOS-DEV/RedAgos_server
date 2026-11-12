<?php

namespace App\Http\Requests;

use App\Rules\PersonName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A person putting themselves on a drive's list through its registration link.
 * Public: the link's token is the only key, and the service refuses a drive
 * that is not taking registrations. No health questions: someone without a
 * portal account is screened in person at the drive (project owner,
 * 2026-11-12).
 */
class StoreDriveSelfRegistrationRequest extends FormRequest
{
    /** A Philippine mobile number, as account registration accepts it. */
    public const MOBILE = '/^(?:\+63|63|0)9\d{9}$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The person rules a partner's entries share (project owner, 2026-11-11):
     * a name, at least one contact, and optionally a birth date and affiliation.
     *
     * @return array<string, mixed>
     */
    public static function personRules(string $prefix = ''): array
    {
        return [
            $prefix.'first_name' => ['required', 'string', 'max:150', new PersonName],
            $prefix.'last_name' => ['required', 'string', 'max:150', new PersonName],
            $prefix.'phone' => ['nullable', 'required_without:'.$prefix.'email', 'string', 'regex:'.self::MOBILE],
            $prefix.'email' => ['nullable', 'required_without:'.$prefix.'phone', 'string', 'email:rfc', 'max:255'],
            $prefix.'birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            $prefix.'affiliation' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * The messages for the person rules, under the same prefix.
     *
     * @return array<string, string>
     */
    public static function personMessages(string $prefix = ''): array
    {
        return [
            $prefix.'first_name.required' => 'Enter the first name.',
            $prefix.'last_name.required' => 'Enter the last name.',
            $prefix.'phone.required_without' => 'Enter a mobile number or an email address.',
            $prefix.'email.required_without' => 'Enter a mobile number or an email address.',
            $prefix.'phone.regex' => 'Enter a Philippine mobile number, such as 0917 123 4567.',
            $prefix.'email.email' => 'Enter a valid email address.',
            $prefix.'birth_date.before' => 'The birth date must be in the past.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...self::personRules(),
            'consent' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...self::personMessages(),
            'consent.accepted' => 'Agree to be contacted about this drive to register.',
        ];
    }
}
