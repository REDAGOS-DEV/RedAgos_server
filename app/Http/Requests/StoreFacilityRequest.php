<?php

namespace App\Http\Requests;

use App\Enums\FacilityTypeName;
use App\Repository\FacilityRepository;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validation for a Super Admin creating a facility and its primary account.
 *
 * Deliberately not shared with anything: the public registration request this
 * replaces accepted a different payload from an unauthenticated caller, and
 * reusing it would have carried its assumptions — one facility type, an
 * applicant awaiting approval — into a flow that has neither.
 *
 * Nothing here can reach the approval trail, the facility type id, the role or
 * the supervisor flag. Those are derived server-side from the validated
 * facility_type and the authenticated administrator.
 */
class StoreFacilityRequest extends FormRequest
{
    /**
     * Authorisation is the route's job: this endpoint sits behind role:admin.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise before validating so the unique rules compare like for like.
     *
     * users.phone stores E.164, so checking a raw local number against the
     * column would never match an existing +63 one and the duplicate would
     * surface as a database error rather than a field-level message.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->filled('phone')) {
            $merge['phone'] = AccountIdentity::normalizePhilippinePhone((string) $this->input('phone'));
        }

        if ($this->filled('email')) {
            $merge['email'] = Str::lower(trim((string) $this->input('email')));
        }

        $account = $this->input('primary_account');

        if (is_array($account)) {
            if (isset($account['phone']) && is_string($account['phone']) && trim($account['phone']) !== '') {
                $account['phone'] = AccountIdentity::normalizePhilippinePhone($account['phone']);
            }

            if (isset($account['email']) && is_string($account['email'])) {
                $account['email'] = Str::lower(trim($account['email']));
            }

            $merge['primary_account'] = $account;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->facilityRules(),
            ...$this->primaryAccountRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'facility_type.required' => 'Select the type of facility to create.',
            'facility_type.in' => 'Choose either a blood center or a hospital blood bank.',
            'name.required' => 'Facility name is required.',
            'name.unique' => 'A facility of this type is already registered under this name.',
            'doh_license_number.required' => 'DOH license number is required.',
            'doh_license_number.unique' => 'This DOH license number belongs to another facility.',
            'address.required' => 'Address is required.',
            'email.required' => 'Facility email address is required.',
            'email.unique' => 'This email address is already used by another facility.',
            'phone.required' => 'Facility phone number is required.',
            'phone.regex' => 'Please enter a valid Philippine mobile number.',
            'phone.unique' => 'This phone number is already used by another facility.',
            'slots_end_at.after' => 'The closing time must be later than the opening time.',
            'primary_account.required' => 'The initial facility account details are required.',
            'primary_account.first_name.required' => 'Primary account first name is required.',
            'primary_account.last_name.required' => 'Primary account last name is required.',
            'primary_account.position.required' => 'Primary account position is required.',
            'primary_account.email.required' => 'Primary account email address is required.',
            'primary_account.email.unique' => 'An account already exists for this email address.',
            'primary_account.phone.required' => 'Primary account phone number is required.',
            'primary_account.phone.regex' => 'Please enter a valid Philippine mobile number.',
            'primary_account.phone.unique' => 'An account already exists for this phone number.',
            'primary_account.username.unique' => 'This username is already taken.',
            'primary_account.password.required' => 'A password for the primary account is required.',
            'primary_account.password.confirmed' => 'Password confirmation does not match.',
        ];
    }

    /**
     * The facility details, including the operating fields.
     *
     * @return array<string, mixed>
     */
    private function facilityRules(): array
    {
        return [
            'facility_type' => ['required', 'string', Rule::in(FacilityTypeName::values())],

            'name' => [
                'required', 'string', 'max:150',
                // facilities carries unique(facility_type_id, name), so this is
                // scoped the same way rather than made globally unique: a blood
                // bank and a blood centre may legitimately share a name.
                Rule::unique('facilities', 'name')->where('facility_type_id', $this->scopedTypeId()),
            ],

            'doh_license_number' => ['required', 'string', 'max:50', 'unique:facilities,doh_license_number'],
            'address' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:150', 'unique:facilities,email'],
            'phone' => ['required', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', 'unique:facilities,phone'],
            'description' => ['nullable', 'string', 'max:1000'],

            // Operating details. Meaningful only for a type that takes donor
            // bookings; the service drops them for one that does not, rather
            // than the rules branching on fields an administrator may have
            // filled in before switching the type.
            'operating_hours' => ['nullable', 'string', 'max:100'],
            'is_accepting_donations' => ['sometimes', 'boolean'],
            'slot_capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'slot_interval_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'slots_start_at' => ['nullable', 'date_format:H:i'],
            'slots_end_at' => ['nullable', 'date_format:H:i', 'after:slots_start_at'],
        ];
    }

    /**
     * The initial account that will run the facility portal.
     *
     * @return array<string, mixed>
     */
    private function primaryAccountRules(): array
    {
        return [
            'primary_account' => ['required', 'array'],
            'primary_account.first_name' => ['required', 'string', 'max:150'],
            'primary_account.last_name' => ['required', 'string', 'max:150'],
            'primary_account.position' => ['required', 'string', 'max:100'],
            'primary_account.email' => ['required', 'string', 'email:rfc', 'max:150', 'unique:users,email'],
            'primary_account.phone' => ['required', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', 'unique:users,phone'],

            // Optional: the service derives one from the email address when it
            // is absent. Validated here so an administrator who does supply one
            // gets a field-level message rather than a unique-index collision.
            'primary_account.username' => ['nullable', 'string', 'max:150', 'unique:users,username'],

            'primary_account.password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];
    }

    /**
     * Resolve the facility type id the name-uniqueness rule is scoped to.
     *
     * Returns 0 for an unrecognised type, which matches no row: the
     * facility_type rule is what reports that submission, and the name rule
     * must not create a facility type row as a side effect of validating one.
     */
    private function scopedTypeId(): int
    {
        $type = FacilityTypeName::tryFrom((string) $this->input('facility_type'));

        return $type === null ? 0 : app(FacilityRepository::class)->typeId($type);
    }
}
