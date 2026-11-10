<?php

namespace App\Http\Requests;

use App\Enums\ValidIdType;
use App\Rules\PersonName;
use App\Rules\ValidIdNumber;
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
            'first_name' => ['required', 'string', 'max:150', new PersonName],
            'last_name' => ['required', 'string', 'max:150', new PersonName],
            'email' => ['required', 'string', 'email:rfc', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', 'unique:users,phone'],
            // Optional: plenty of first-time donors genuinely do not know their
            // type, and forcing the field only makes them guess. A guess is
            // worse than a blank, because the laboratory back-fill in
            // LaboratoryService only fills a *null* type — a wrong guess is
            // never corrected and instead refuses the donation later with
            // `blood_type_mismatch`. Left empty, the donor's first cleared
            // donation records it for them.
            'blood_type' => ['nullable', 'string', 'max:10', 'exists:blood_types,code'],
            'gender' => ['required', 'string', 'in:male,female,other,prefer_not_to_say'],
            'birth_date' => ['required', 'date', 'before_or_equal:'.now()->subYears(self::MINIMUM_AGE_YEARS)->toDateString()],
            'address' => ['required', 'string', 'max:255'],

            // Section I-A of the DOH questionnaire is deliberately NOT collected
            // here. Signing up is already a long form, and every one of those
            // fields can be filled in later from the donor's profile, which is
            // where they are edited anyway. A donor who never fills them in is
            // not blocked: the columns are nullable and the blood centre's
            // questionnaire prints a gap as "Not provided".

            // Required at signup (project owner, 2026-11-10): every portal donor
            // names the ID they will present at the counter, its type and its
            // number in that type's shape. The photo of it is not taken here;
            // the registration page sends it, when one was chosen, through the
            // profile's upload (POST /donors/identity) after the first sign-in.
            'valid_id_type' => ['required', 'string', Rule::in(ValidIdType::values())],
            'valid_id_number' => [
                'required', 'string', 'max:50',
                new ValidIdNumber($this->input('valid_id_type')),
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
            'blood_type.exists' => 'Please select a valid blood type.',
            'gender.required' => 'Gender is required.',
            'gender.in' => 'Please select a valid gender.',
            'birth_date.required' => 'Date of birth is required.',
            'birth_date.date' => 'Please enter a valid date of birth.',
            'birth_date.before_or_equal' => 'You must be at least '.self::MINIMUM_AGE_YEARS.' years old to register as a donor.',
            'address.required' => 'Address is required.',
            'address.max' => 'Address must not be greater than 255 characters.',
            'valid_id_type.required' => 'Please choose your valid ID.',
            'valid_id_type.in' => 'Please select a valid ID type.',
            'valid_id_number.required' => 'Please enter the number on your ID.',
            'valid_id_number.max' => 'The ID number must not be greater than 50 characters.',
            // Deliberately does not confirm that another account holds it.
            'valid_id_number.unique' => 'This ID is already on file. Please contact support.',
            'password.required' => 'Password is required.',
            'password.confirmed' => 'Password confirmation does not match.',
            'terms_accepted.accepted' => 'You must agree to the Terms of Service and Privacy Policy.',
        ];
    }
}
