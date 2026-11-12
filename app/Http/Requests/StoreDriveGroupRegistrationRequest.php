<?php

namespace App\Http\Requests;

use App\Rules\PersonName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A partner organisation putting its people on a drive's list through the
 * drive's registration link. No partner account: the organisation and its
 * contact are typed with the list. Each person meets the same rules as a
 * person registering themselves.
 */
class StoreDriveGroupRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organisation_name' => ['required', 'string', 'max:150'],
            'contact_name' => ['required', 'string', 'max:150', new PersonName],
            'contact_phone' => ['nullable', 'required_without:contact_email', 'string', 'regex:'.StoreDriveSelfRegistrationRequest::MOBILE],
            'contact_email' => ['nullable', 'required_without:contact_phone', 'string', 'email:rfc', 'max:255'],
            'consent' => ['accepted'],
            'people' => ['required', 'array', 'min:1', 'max:'.(int) config('donation.drive_group_max_people')],
            ...StoreDriveSelfRegistrationRequest::personRules('people.*.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'organisation_name.required' => 'Enter the organisation\'s name.',
            'contact_name.required' => 'Enter the contact person\'s name.',
            'contact_phone.required_without' => 'Enter a mobile number or an email address for the contact person.',
            'contact_email.required_without' => 'Enter a mobile number or an email address for the contact person.',
            'contact_phone.regex' => 'Enter a Philippine mobile number, such as 0917 123 4567.',
            'consent.accepted' => 'Confirm that the people listed agreed to be contacted about this drive.',
            'people.required' => 'Add at least one person.',
            'people.min' => 'Add at least one person.',
            'people.max' => 'Send at most '.(int) config('donation.drive_group_max_people').' people at a time.',
            ...StoreDriveSelfRegistrationRequest::personMessages('people.*.'),
        ];
    }
}
