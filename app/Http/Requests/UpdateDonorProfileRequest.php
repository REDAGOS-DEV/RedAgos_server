<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDonorProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:150'],
            'last_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($this->user()?->id)],
            'phone' => ['required', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', Rule::unique('users', 'phone')->ignore($this->user()?->id)],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'blood_type' => ['required', 'string', 'max:10', 'exists:blood_types,code'],
            'address' => ['required', 'string', 'max:255'],
        ];
    }
}
