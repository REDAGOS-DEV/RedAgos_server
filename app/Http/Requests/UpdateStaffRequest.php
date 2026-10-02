<?php

namespace App\Http\Requests;

use App\Enums\AccountStatus;
use App\Enums\Department;
use App\Enums\StaffPrivilege;
use App\Enums\StaffRole;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStaffRequest extends FormRequest
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
        $uuid = (string) $this->route('uuid');

        return [
            'first_name' => ['sometimes', 'string', 'max:150'],
            'last_name' => ['sometimes', 'string', 'max:150'],
            'phone' => [
                'sometimes', 'nullable', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/',
                Rule::unique('users', 'phone')->ignore($uuid, 'uuid'),
            ],
            'position' => ['sometimes', 'nullable', 'string', 'max:100'],
            'employee_id' => [
                'sometimes', 'nullable', 'string', 'max:50',
                Rule::unique('users', 'employee_id')
                    ->where('facility_id', $this->user()?->facility_id)
                    ->ignore($uuid, 'uuid'),
            ],

            // Nullable here rather than conditionally required: a partial
            // update may clear a role while the same request grants the
            // supervisor level. StaffService checks the resulting combination,
            // which is the only place both values are known.
            'department' => ['sometimes', 'nullable', 'string', Rule::in(Department::values())],
            'staff_role' => ['sometimes', 'nullable', 'string', Rule::in(StaffRole::values())],
            'custom_role' => ['sometimes', 'nullable', 'string', 'max:100'],
            'staff_privileges' => ['sometimes', 'array', 'min:1'],
            'staff_privileges.*' => ['string', 'distinct', Rule::in(StaffPrivilege::values())],
            'is_supervisor' => ['sometimes', 'boolean'],

            // pending_verification is deliberately absent: it is set at
            // creation and cleared by the holder verifying their address, not
            // something a supervisor reaches back into.
            'account_status' => [
                'sometimes', 'string',
                Rule::in([
                    AccountStatus::Active->value,
                    AccountStatus::Suspended->value,
                    AccountStatus::Deactivated->value,
                ]),
            ],
        ];
    }

    /**
     * Refuse an update that names no field at all.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $this->safe()->all() === []) {
                    $validator->errors()->add('staff_role', 'Provide at least one field to update.');
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
            'phone.unique' => 'An account already exists for this phone number.',
            'employee_id.unique' => 'Another staff member at this facility already has this employee ID.',
        ];
    }
}
