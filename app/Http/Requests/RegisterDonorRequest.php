<?php

namespace App\Http\Requests;

use App\Enums\ValidIdType;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterDonorRequest extends FormRequest
{
    /**
     * Minimum age for whole-blood donor registration in the Philippines.
     */
    private const MINIMUM_AGE_YEARS = 18;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the ID number before validating so the unique rule compares
     * like for like.
     *
     * Without this, "PH-DL-1234" would pass a uniqueness check against a stored
     * "PHDL1234" and the same person could hold two donor records.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('valid_id_number')) {
            $this->merge([
                'valid_id_number' => AccountIdentity::normalizeValidIdNumber(
                    $this->input('valid_id_number')
                ),
            ]);
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
            'phone' => ['required', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', 'unique:users,phone'],
            'blood_type' => ['required', 'string', 'max:10', 'exists:blood_types,code'],
            'gender' => ['required', 'string', 'in:male,female,other,prefer_not_to_say'],
            'birth_date' => ['required', 'date', 'before_or_equal:'.now()->subYears(self::MINIMUM_AGE_YEARS)->toDateString()],
            'address' => ['required', 'string', 'max:255'],

            // Optional at signup: the donor may supply the ID they will present
            // at the counter now, or upload the document later from their
            // profile. Either way it is the type and number together or neither.
            'valid_id_type' => ['nullable', 'string', Rule::in(ValidIdType::values()), 'required_with:valid_id_number'],
            'valid_id_number' => [
                'nullable', 'string', 'max:50',
                'required_with:valid_id_type',
                'unique:donor_profiles,valid_id_number',
            ],

            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'terms_accepted' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'First name is required.',
            'first_name.max' => 'First name must not be greater than 150 characters.',
            'last_name.required' => 'Last name is required.',
            'last_name.max' => 'Last name must not be greater than 150 characters.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email address is already registered.',
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Please enter a valid Philippine mobile number.',
            'phone.unique' => 'This phone number is already registered.',
            'blood_type.required' => 'Blood type is required.',
            'blood_type.exists' => 'Please select a valid blood type.',
            'gender.required' => 'Gender is required.',
            'gender.in' => 'Please select a valid gender.',
            'birth_date.required' => 'Date of birth is required.',
            'birth_date.date' => 'Please enter a valid date of birth.',
            'birth_date.before_or_equal' => 'You must be at least '.self::MINIMUM_AGE_YEARS.' years old to register as a donor.',
            'address.required' => 'Address is required.',
            'address.max' => 'Address must not be greater than 255 characters.',
            'valid_id_type.in' => 'Please select a valid ID type.',
            'valid_id_type.required_with' => 'Please choose which ID this number belongs to.',
            'valid_id_number.required_with' => 'Please enter the number on your ID.',
            // Deliberately does not confirm that another account holds it.
            'valid_id_number.unique' => 'This ID is already on file. Please contact support.',
            'password.required' => 'Password is required.',
            'password.confirmed' => 'Password confirmation does not match.',
            'terms_accepted.accepted' => 'You must agree to the Terms of Service and Privacy Policy.',
        ];
    }
}
