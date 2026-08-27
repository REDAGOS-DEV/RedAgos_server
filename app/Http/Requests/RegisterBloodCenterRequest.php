<?php

namespace App\Http\Requests;

use App\Repository\BloodCenterRepository;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterBloodCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise before validating so the unique rules compare like for like.
     *
     * users.phone stores E.164, so checking a raw "09..." against the column
     * would never match an existing "+639..." and a duplicate would slip
     * through to a database error instead of a field-level message.
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
        return [
            'center_name' => [
                'required', 'string', 'max:150',
                // facilities carries unique(facility_type_id, name), so this is
                // scoped the same way rather than made globally unique: a blood
                // bank and a blood centre may legitimately share a name.
                Rule::unique('facilities', 'name')
                    ->where('facility_type_id', app(BloodCenterRepository::class)->bloodCenterTypeId()),
            ],
            'doh_license_number' => ['required', 'string', 'max:50', 'unique:facilities,doh_license_number'],
            'contact_first_name' => ['required', 'string', 'max:150'],
            'contact_last_name' => ['required', 'string', 'max:150'],
            'position' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', 'unique:users,phone'],
            'address' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'center_name.required' => 'Blood center name is required.',
            'center_name.unique' => 'A blood center with this name is already registered.',
            'doh_license_number.required' => 'DOH license number is required.',
            'doh_license_number.unique' => 'This DOH license number is already registered. Please contact support if this is your organisation.',
            'contact_first_name.required' => 'Contact person first name is required.',
            'contact_last_name.required' => 'Contact person last name is required.',
            'position.required' => 'Position is required.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email address is already registered.',
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Please enter a valid Philippine mobile number.',
            'phone.unique' => 'This phone number is already registered.',
            'address.required' => 'Address is required.',
            'description.max' => 'Description must not be greater than 1000 characters.',
            'password.required' => 'Password is required.',
            'password.confirmed' => 'Password confirmation does not match.',
        ];
    }
}
