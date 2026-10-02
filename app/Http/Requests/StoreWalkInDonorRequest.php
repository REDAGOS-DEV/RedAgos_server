<?php

namespace App\Http\Requests;

use App\Support\AccountIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreWalkInDonorRequest extends FormRequest
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

        if ($this->has('valid_id_number')) {
            $this->merge([
                'valid_id_number' => AccountIdentity::normalizeValidIdNumber(
                    $this->input('valid_id_number')
                ),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:150'],
            'last_name' => ['required', 'string', 'max:150'],

            // The identifier a walk-in actually carries, and what stops the same
            // person being registered twice under two spellings of their name.
            'valid_id_number' => ['required', 'string', 'max:50', 'unique:donor_profiles,valid_id_number'],

            // Optional by decision: a counter-registered donor may have no
            // address, and an invented one is worse than none.
            'email' => ['nullable', 'string', 'email:rfc', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', 'unique:users,phone'],

            'birth_date' => ['required', 'date', 'before_or_equal:'.now()->subYears((int) config('donation.min_age_years'))->toDateString()],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'blood_type_id' => ['nullable', 'integer', 'exists:blood_types,id'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valid_id_number.required' => 'A valid ID number is required to register a donor at the counter.',
            'valid_id_number.unique' => 'A donor is already registered with this ID. Search for them instead.',
            'email.unique' => 'An account already exists for this email address.',
            'phone.unique' => 'An account already exists for this phone number.',
            'birth_date.before_or_equal' => 'A donor must be at least '.config('donation.min_age_years').' years old.',
        ];
    }
}
