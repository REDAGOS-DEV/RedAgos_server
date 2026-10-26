<?php

namespace App\Http\Requests;

use App\Enums\Department;
use App\Enums\ManagementLevel;
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
        if ($this->filled('custom_role')) {
            $typed = StaffRole::fromTyped((string) $this->input('custom_role'));

            if ($typed !== null && ! $this->filled('staff_role')) {
                $this->merge(['staff_role' => $typed->value, 'custom_role' => null]);
            } elseif ($typed !== null) {
                // Beside a main role it is one more of their roles.
                $this->merge([
                    'additional_staff_roles' => array_values(array_unique([...(array) $this->input('additional_staff_roles', []), $typed->value])),
                    'custom_role' => null,
                ]);
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
            // The title, printed after the name on everything the staff member
            // signs: RMT, MD, RN. Blood-centre accounts keep no job post beside
            // it (project owner, 2026-10-22).
            'credentials' => ['nullable', 'string', 'max:40', 'regex:/^[\pL\d][\pL\d.,&()\/ -]*$/u'],

            // users carries unique(facility_id, employee_id), so the rule is
            // scoped the same way: two centres may both have a badge "001".
            'employee_id' => [
                'nullable', 'string', 'max:50',
                Rule::unique('users', 'employee_id')
                    ->where('facility_id', $this->user()?->facility_id),
            ],

            // A predefined role, or a custom one typed in its place. An
            // account with no management level needs one or the other
            // (checked in after()); a custom role also needs a department,
            // which a predefined role supplies itself.
            'department' => ['nullable', 'string', Rule::in(Department::values())],
            'staff_role' => ['nullable', 'string', Rule::in(StaffRole::values())],
            // Their other roles, all in the main role's department (checked by
            // StaffService, which names the role that does not fit).
            'additional_staff_roles' => ['sometimes', 'nullable', 'array', 'max:10'],
            'additional_staff_roles.*' => ['string', 'distinct', Rule::in(StaffRole::values())],
            'custom_role' => ['nullable', 'string', 'max:100'],

            // The Read / Write / Update / Delete cap. Omitted means all four.
            'staff_privileges' => ['sometimes', 'array', 'min:1'],
            'staff_privileges.*' => ['string', 'distinct', Rule::in(StaffPrivilege::values())],

            // Admin or Supervisor, or null for an account that only does its
            // role's work.
            'management_level' => ['sometimes', 'nullable', Rule::enum(ManagementLevel::class)],

            // Replaced by management_level. Refused rather than ignored, so a
            // caller still sending it learns the account did not get a level.
            'is_supervisor' => ['prohibited'],

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

                // With several roles and no main one, the first is the main one.
                $role = StaffRole::tryFrom((string) $this->input('staff_role'))
                    ?? StaffRole::tryFrom((string) (((array) $this->input('additional_staff_roles', []))[0] ?? ''));
                $department = Department::tryFrom((string) $this->input('department'));

                if ($role === null && ! $this->filled('custom_role')) {
                    if (! $this->filled('management_level')) {
                        $validator->errors()->add('staff_role', 'Choose or type a role, or give them the Admin or Supervisor level instead.');
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
            'credentials.regex' => 'Write the credentials as letters, for example RMT, or MD, FPCP.',
            'credentials.max' => 'Keep the credentials to 40 characters.',
            'email.unique' => 'An account already exists for this email address.',
            'is_supervisor.prohibited' => 'is_supervisor was replaced by management_level: admin or supervisor.',
            'phone.unique' => 'An account already exists for this phone number.',
            'phone.regex' => 'Please enter a valid Philippine mobile number.',
            'employee_id.unique' => 'Another staff member at this facility already has this employee ID.',
            'staff_privileges.min' => 'Tick at least one privilege.',
        ];
    }
}
