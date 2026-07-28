<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
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
            'account_status' => ['sometimes', 'in:pending_activation,active,inactive,suspended'],
            'activated_at' => ['nullable', 'date'],
            'roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string', 'exists:roles,name'],
        ];
    }
}
