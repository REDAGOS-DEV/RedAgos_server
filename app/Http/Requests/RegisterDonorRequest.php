<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterDonorRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', 'unique:users,phone'],
            'blood_type' => ['required', 'string', 'max:10', 'exists:blood_types,code'],
            'gender' => ['required', 'string', 'in:male,female,other,prefer_not_to_say'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'address' => ['required', 'string', 'max:255'],
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
            'birth_date.before_or_equal' => 'Date of birth cannot be in the future.',
            'address.required' => 'Address is required.',
            'address.max' => 'Address must not be greater than 255 characters.',
            'password.required' => 'Password is required.',
            'password.confirmed' => 'Password confirmation does not match.',
            'terms_accepted.accepted' => 'You must agree to the Terms of Service and Privacy Policy.',
        ];
    }
}
