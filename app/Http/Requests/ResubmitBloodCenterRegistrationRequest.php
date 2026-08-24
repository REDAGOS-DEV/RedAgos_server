<?php

namespace App\Http\Requests;

use App\Repository\BloodCenterRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResubmitBloodCenterRegistrationRequest extends FormRequest
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
        // Ignoring the caller's own facility is what lets a legitimate
        // organisation resubmit under the licence it actually holds. Ownership
        // is enforced in the service, not here — this only relaxes uniqueness.
        $facilityId = $this->user()?->facility_id;

        return [
            'center_name' => [
                'required', 'string', 'max:150',
                Rule::unique('facilities', 'name')
                    ->where('facility_type_id', app(BloodCenterRepository::class)->bloodCenterTypeId())
                    ->ignore($facilityId),
            ],
            'doh_license_number' => [
                'required', 'string', 'max:50',
                Rule::unique('facilities', 'doh_license_number')->ignore($facilityId),
            ],
            'contact_person' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'center_name.unique' => 'A different blood center with this name is already registered.',
            'doh_license_number.unique' => 'This DOH license number belongs to a different organisation.',
            'contact_person.required' => 'Contact person is required.',
        ];
    }
}
