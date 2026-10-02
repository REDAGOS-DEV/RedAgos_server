<?php

namespace App\Http\Requests;

use App\Enums\Department;
use App\Enums\StaffPrivilege;
use App\Enums\StaffRole;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

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

        // A typed role that names a predefined one is that role, so the
        // roster never holds a custom "Laboratory Supervisor" beside the real
        // one with different abilities.
        if ($this->filled('custom_role') && ! $this->filled('staff_role')) {
            $typed = StaffRole::fromTyped((string) $this->input('custom_role'));

            if ($typed !== null) {
                $this->merge(['staff_role' => $typed->value, 'custom_role' => null]);
            }
        }

        if ($this->filled('custom_role')) {
            $this->merge(['custom_role' => trim((string) $this->input('custom_role'))]);
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
            // The title — RMT, RN, or anything typed. A label only.
            'position' => ['nullable', 'string', 'max:100'],

            // users carries unique(facility_id, employee_id), so the rule is
            // scoped the same way: two centres may both have a badge "001".
            'employee_id' => [
                'nullable', 'string', 'max:50',
                Rule::unique('users', 'employee_id')
                    ->where('facility_id', $this->user()?->facility_id),
            ],

            // A predefined role, or a custom one typed in its place. A
            // non-supervisor needs one or the other (checked in after()); a
            // custom role also needs a department, which a predefined role
            // supplies itself.
            'department' => ['nullable', 'string', Rule::in(Department::values())],
            'staff_role' => ['nullable', 'string', Rule::in(StaffRole::values())],
            'custom_role' => ['nullable', 'string', 'max:100'],

            // The Read / Write / Update / Delete cap. Omitted means all four.
            'staff_privileges' => ['sometimes', 'array', 'min:1'],
            'staff_privileges.*' => ['string', 'distinct', Rule::in(StaffPrivilege::values())],

            'is_supervisor' => ['sometimes', 'boolean'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];
    }

    /**
     * The role and department have to make sense together.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $role = StaffRole::tryFrom((string) $this->input('staff_role'));
                $department = Department::tryFrom((string) $this->input('department'));

                if ($role === null && ! $this->filled('custom_role')) {
                    if (! $this->boolean('is_supervisor')) {
                        $validator->errors()->add('staff_role', 'Choose or type a role, or grant the supervisor level instead.');
                    }

                    return;
                }

                if ($role !== null && $department !== null && $role->department() !== $department) {
                    $validator->errors()->add(
                        'staff_role',
                        "{$role->label()} is in {$role->department()->label()}, not {$department->label()}."
                    );
                }

                if ($role === null && $department === null) {
                    $validator->errors()->add('department', 'Choose the department this role works in.');
                }
            },
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
            'staff_privileges.min' => 'Tick at least one privilege.',
        ];
    }
}
