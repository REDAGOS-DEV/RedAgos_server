<?php

namespace App\Http\Requests;

use App\Enums\AccountStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $uuid = $this->route('uuid');

        return [
            'first_name' => ['sometimes', 'string', 'max:150'], 'last_name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($uuid, 'uuid')],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($uuid, 'uuid')],
            'username' => ['sometimes', 'string', 'max:150', Rule::unique('users', 'username')->ignore($uuid, 'uuid')],
            'password' => ['sometimes', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'account_status' => ['sometimes', Rule::enum(AccountStatus::class)],
            'activated_at' => ['nullable', 'date'],
            'roles' => ['sometimes', 'array', 'min:1'], 'roles.*' => ['string', 'exists:roles,name'],
        ];
    }
}
