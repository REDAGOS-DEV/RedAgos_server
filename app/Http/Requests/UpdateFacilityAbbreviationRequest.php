<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A Super Admin setting or correcting a blood centre's abbreviation, the
 * first part of every bag number it books (SNBC-21627990-PRBC).
 *
 * The same rules as creating the centre (StoreFacilityRequest). Whether it
 * may still change — it may not once the centre has booked a bag — is the
 * service's to decide, under a lock.
 */
class UpdateFacilityAbbreviationRequest extends FormRequest
{
    /**
     * Authorisation is the route's job: this endpoint sits behind role:admin.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('abbreviation'))) {
            $this->merge(['abbreviation' => strtoupper(trim($this->input('abbreviation')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'abbreviation' => [
                'required', 'string', 'regex:/^[A-Z]{2,6}$/',
                Rule::unique('facilities', 'abbreviation')->ignore((int) $this->route('facility')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'abbreviation.required' => 'Enter the blood center’s abbreviation, such as SNBC.',
            'abbreviation.regex' => 'An abbreviation is 2 to 6 letters, such as SNBC.',
            'abbreviation.unique' => 'Another blood center already uses this abbreviation.',
        ];
    }
}
