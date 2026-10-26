<?php

namespace App\Http\Requests;

use App\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `login` takes an email address or a username, and is what the single
     * sign-in page sends. `email` is the older field, still accepted from the
     * pages that send it. AuthService reads `login` first.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'login' => ['required_without:email', 'nullable', 'string', 'max:150'],
            'email' => ['required_without:login', 'nullable', 'string', 'email:rfc', 'max:150'],
            'password' => ['required', 'string'],
            'role' => ['nullable', 'string', Rule::in(RoleName::allAcceptedValues())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.required_without' => 'Email address or username is required.',
            'email.required_without' => 'Email address is required.',
            'email.email' => 'Please enter a valid email address.',
            'password.required' => 'Password is required.',
            'role.in' => 'Unknown account portal.',
        ];
    }
}
