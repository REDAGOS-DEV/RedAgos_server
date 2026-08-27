<?php

namespace App\Http\Requests;

use App\Support\AccountIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBloodCenterProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('phone')) {
            $this->merge([
                'phone' => AccountIdentity::normalizePhilippinePhone((string) $this->input('phone')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:150'],
            'last_name' => ['sometimes', 'required', 'string', 'max:150'],
            'phone' => [
                'sometimes', 'required', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/',
                Rule::unique('users', 'phone')->ignore($user?->id),
            ],
            // Mirrors unique(facility_id, employee_id): a badge number need only
            // be unique inside its own facility.
            'employee_id' => [
                'sometimes', 'nullable', 'string', 'max:50',
                Rule::unique('users', 'employee_id')
                    ->where('facility_id', $user?->facility_id)
                    ->ignore($user?->id),
            ],
            'position' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Please enter a valid Philippine mobile number.',
            'phone.unique' => 'This phone number is already registered.',
            'employee_id.unique' => 'This employee ID is already used at this facility.',
        ];
    }
}
