<?php

namespace App\Http\Requests;

use App\Models\Facility;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A Super Admin correcting a facility's details.
 *
 * Only the facility record: its type, status and approval trail are not
 * editable here (status has its own deactivate and reactivate routes), and the
 * accounts that run it are kept elsewhere. Every field is optional, so a
 * correction sends only what changed; the unique rules ignore the facility
 * being edited.
 */
class UpdateFacilityRequest extends FormRequest
{
    /**
     * Authorisation is the route's job: this endpoint sits behind role:admin and can:.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise before validating so the unique rules compare like for like.
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

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $facility = $this->facility();
        $id = $facility?->id ?? 0;

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:150',
                // Scoped to the facility's own type, as on creation.
                Rule::unique('facilities', 'name')
                    ->where('facility_type_id', $facility?->facility_type_id ?? 0)
                    ->ignore($id),
            ],
            'doh_license_number' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('facilities', 'doh_license_number')->ignore($id)],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:150', Rule::unique('facilities', 'email')->ignore($id)],
            'phone' => ['sometimes', 'required', 'string', 'regex:/^(?:\+63|63|0)9\d{9}$/', Rule::unique('facilities', 'phone')->ignore($id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'operating_hours' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_accepting_donations' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A facility of this type is already registered under this name.',
            'doh_license_number.unique' => 'This DOH license number belongs to another facility.',
            'email.unique' => 'This email address is already used by another facility.',
            'phone.regex' => 'Please enter a valid Philippine mobile number.',
            'phone.unique' => 'This phone number is already used by another facility.',
        ];
    }

    /**
     * The facility in the route, for the unique rules to ignore.
     */
    private function facility(): ?Facility
    {
        return Facility::query()->find((int) $this->route('facility'));
    }
}
