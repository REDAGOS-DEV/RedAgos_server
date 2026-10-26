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
            // The title, printed after the name on everything the staff member
            // signs: RMT, MD, RN. Blood-centre accounts keep no job post beside
            // it (project owner, 2026-10-22).
            'credentials' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[\pL\d][\pL\d.,&()\/ -]*$/u'],
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
            'phone.regex' => 'Please enter a valid Philippine mobile number.',
            'phone.unique' => 'This phone number is already registered.',
            'employee_id.unique' => 'This employee ID is already used at this facility.',
        ];
    }
}
