<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GoogleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The ID token Google Identity Services handed the browser.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'credential' => ['required', 'string', 'max:4096'],
        ];
    }
}
