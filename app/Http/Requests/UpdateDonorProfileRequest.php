<?php

namespace App\Http\Requests;

use App\Enums\CivilStatus;
use App\Enums\MailingAddressPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDonorProfileRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:150'],
            'last_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($this->user()?->id)],
            'phone' => ['required', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', Rule::unique('users', 'phone')->ignore($this->user()?->id)],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            // Nullable for the same reason as registration: a donor whose type
            // is not known yet must be able to edit their address without being
            // forced to invent one.
            'blood_type' => ['nullable', 'string', 'max:10', 'exists:blood_types,code'],
            'address' => ['required', 'string', 'max:255'],

            // Section I-A. All optional: required-ness at registration is one
            // thing, but a donor editing their address must not be blocked
            // because a field that did not exist when they signed up is empty.
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'civil_status' => ['sometimes', 'nullable', 'string', Rule::in(CivilStatus::values())],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:100'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:60'],
            'religion' => ['sometimes', 'nullable', 'string', 'max:60'],
            'preferred_mailing_address' => ['sometimes', 'nullable', 'string', Rule::in(MailingAddressPreference::values())],
            'office_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'telephone_no' => ['sometimes', 'nullable', 'string', 'max:20'],
            'contact_person_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'contact_person_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_person_number' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }
}
