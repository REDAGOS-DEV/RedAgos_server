<?php

namespace App\Http\Requests;

use App\Enums\ValidIdType;
use App\Rules\PersonName;
use App\Rules\ValidIdNumber;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreWalkInDonorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise before validating so the unique rules compare like for like.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('phone')) {
            $this->merge([
                'phone' => AccountIdentity::normalizePhilippinePhone((string) $this->input('phone')),
            ]);
        }

        if ($this->filled('email')) {
            $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
        }

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

            // The identifier a walk-in actually carries, and what stops the same
            // person being registered twice under two spellings of their name.
            //
            // Its type says the number's shape. Donor Management always picks
            // one; a paper sheet may not say which ID was shown, so it stays
            // optional here and the number is then taken as written.
            'valid_id_type' => ['nullable', 'string', Rule::in(ValidIdType::values())],
            'valid_id_number' => [
                'required', 'string', 'max:50',
                new ValidIdNumber($this->input('valid_id_type')),
                'unique:donor_profiles,valid_id_number',
            ],

            // Optional by decision: a counter-registered donor may have no
            // address, and an invented one is worse than none.
            'email' => ['nullable', 'string', 'email:rfc', 'max:150', 'unique:users,email'],

            // Personal or Shared (project owner, 2026-11-12): a household may
            // share one phone. Whether the number may be used is decided with
            // the other accounts holding it (DonorDirectoryService), not by a
            // unique rule: a Shared number repeats, a Personal one does not,
            // and someone else's Personal number needs staff to confirm.
            'phone' => ['nullable', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/'],
            'phone_shared' => ['sometimes', 'boolean'],
            'confirm_shared_phone' => ['sometimes', 'boolean'],

            'birth_date' => ['required', 'date', 'before_or_equal:'.now()->subYears((int) config('donation.min_age_years'))->toDateString()],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'blood_type_id' => ['nullable', 'integer', 'exists:blood_types,id'],
            'address' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:100'],

            // A donation given before RedAgos knew them, as the donor recalls
            // it. Only read when no draw is on record.
            'last_donation_date' => ['nullable', 'date', 'before_or_equal:today'],

            // This centre's own record of them.
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valid_id_type.in' => 'Please select a valid ID type.',
            'valid_id_number.required' => 'A valid ID number is required to register a donor at the counter.',
            'valid_id_number.unique' => 'A donor is already registered with this ID. Search for them instead.',
            'email.unique' => 'An account already exists for this email address.',
            'birth_date.before_or_equal' => 'A donor must be at least '.config('donation.min_age_years').' years old.',
        ];
    }
}
