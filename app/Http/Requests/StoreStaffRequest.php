<?php

namespace App\Http\Requests;

use App\Enums\Department;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise before validating so the unique rules compare like for like.
     *
     * users.phone stores E.164, so checking a raw "09..." against the column
     * would never match an existing "+639..." and the duplicate would surface
     * as a database error rather than a field-level message.
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
            'first_name' => ['required', 'string', 'max:150'],
            'last_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', 'unique:users,phone'],
            'position' => ['nullable', 'string', 'max:100'],

            // users carries unique(facility_id, employee_id), so the rule is
            // scoped the same way: two centres may both have a badge "001".
            'employee_id' => [
                'nullable', 'string', 'max:50',
                Rule::unique('users', 'employee_id')
                    ->where('facility_id', $this->user()?->facility_id),
            ],

            // A non-supervisor with no department holds no abilities at all, so
            // it is required unless the account is being given the management
            // level. Resolved through boolean() rather than required_unless so
            // a JSON true and a form "1" are read the same way.
            'department' => [
                Rule::requiredIf(fn (): bool => ! $this->boolean('is_supervisor')),
                'nullable', 'string', Rule::in(Department::values()),
            ],

            'is_supervisor' => ['sometimes', 'boolean'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account already exists for this email address.',
            'phone.unique' => 'An account already exists for this phone number.',
            'phone.regex' => 'Please enter a valid Philippine mobile number.',
            'employee_id.unique' => 'Another staff member at this facility already has this employee ID.',
            'department.required' => 'Select a department, or grant the supervisor level instead.',
        ];
    }
}
