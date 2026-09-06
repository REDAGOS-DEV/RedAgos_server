<?php

namespace App\Http\Requests;

use App\Enums\AccountStatus;
use App\Support\AdminPrivileges;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:150'], 'last_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'username' => ['required', 'string', 'max:150', 'unique:users,username'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'account_status' => ['sometimes', Rule::enum(AccountStatus::class)],
            'activated_at' => ['nullable', 'date'],
            'roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string', 'exists:roles,name'],

            'admin_privileges' => ['nullable', 'array'],
            'admin_privileges.*' => ['string', Rule::in(AdminPrivileges::all())],

            /*
             * Escalation guard. admin.accounts.manage is what lets an admin
             * create accounts at all, but a scoped admin handing itself — or a
             * confederate — the unrestricted flag would make every other `can:`
             * on these routes decorative. Only an account that already holds
             * unrestricted access may confer it.
             */
            'is_super_admin' => ['sometimes', 'boolean', function (string $attribute, mixed $value, callable $fail): void {
                if ($value && ! $this->user()?->is_super_admin) {
                    $fail('Only an unrestricted administrator may grant unrestricted access.');
                }
            }],
        ];
    }
}
