<?php

namespace App\Http\Requests;

use App\Enums\HospitalRole;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * A blood bank Head adding a colleague, with their role (project owner, 2026-10-11).
 *
 * The account fields follow StoreStaffRequest, the blood centre's: the address
 * is lowercased and the phone normalised before validation, and the Head sets
 * the first password, which the colleague signs in with once their address
 * is verified.
 */
class StoreHospitalStaffRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware, as elsewhere in this application.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:150'],
            'last_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', 'unique:users,phone'],
            // The title — RMT, RN, or anything typed. A label only.
            'position' => ['nullable', 'string', 'max:100'],
            'hospital_role' => ['required', 'string', Rule::in(HospitalRole::values())],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];
    }

    /**
     * Normalise the address and phone before they are checked for uniqueness.
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
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account already exists for this email address.',
            'phone.unique' => 'An account already exists for this phone number.',
            'phone.regex' => 'Please enter a valid Philippine mobile number.',
            'hospital_role.required' => 'Choose a role.',
        ];
    }
}
