<?php

namespace App\Http\Requests;

use App\Enums\FacilityDonorStatus;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A correction to a donor's record from the Donors page.
 *
 * Every field is optional: what is sent is what changes. Which fields this
 * centre may change for this donor is decided in DonorDirectoryService::update(),
 * because it depends on the donor rather than the input.
 */
class UpdateDonorRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise before validating so the unique rules compare like for like.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('phone')) {
            $this->merge([
                'phone' => AccountIdentity::normalizePhilippinePhone((string) $this->input('phone')),
            ]);
        }

        if ($this->filled('email')) {
            $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $uuid = (string) $this->route('uuid');

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:150'],
            'last_name' => ['sometimes', 'required', 'string', 'max:150'],
            'birth_date' => ['sometimes', 'required', 'date', 'before_or_equal:'.now()->subYears((int) config('donation.min_age_years'))->toDateString()],
            'gender' => ['sometimes', 'nullable', 'string', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'blood_type_id' => ['sometimes', 'nullable', 'integer', 'exists:blood_types,id'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($uuid, 'uuid')],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', Rule::unique('users', 'phone')->ignore($uuid, 'uuid')],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_donation_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'status' => ['sometimes', 'required', 'string', Rule::in(FacilityDonorStatus::values())],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Another account already uses this email address.',
            'phone.unique' => 'Another account already uses this phone number.',
            'phone.regex' => 'Enter a Philippine mobile number, such as 09171234567.',
            'birth_date.before_or_equal' => 'A donor must be at least '.config('donation.min_age_years').' years old.',
        ];
    }
}
