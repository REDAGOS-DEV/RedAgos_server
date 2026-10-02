<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tag a bag in the hospital's own stock to a patient.
 *
 * The patient is entered on the tag, because a patient served from the
 * hospital's own shelf has no requirement to point at. Naming one of the
 * hospital's Patient Transfusion Requests instead fills in what is left out;
 * whether that request exists, belongs to this hospital and is still open is
 * checked by HospitalInventoryService, under the bag's lock.
 */
class TagHospitalUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'transfusion_request_id' => ['nullable', 'integer'],

            'patient_surname' => ['required_without:transfusion_request_id', 'nullable', 'string', 'max:100'],
            'patient_first_name' => ['required_without:transfusion_request_id', 'nullable', 'string', 'max:100'],
            'patient_middle_name' => ['nullable', 'string', 'max:100'],
            // 130 is a sanity guard on a typed figure, not a clinical rule.
            'patient_age' => ['required_without:transfusion_request_id', 'nullable', 'integer', 'min:0', 'max:130'],
            'patient_sex' => ['required_without:transfusion_request_id', 'nullable', Rule::in(['male', 'female'])],
            'patient_record_number' => ['nullable', 'string', 'max:60'],
            'patient_ward' => ['nullable', 'string', 'max:100'],
            'attending_physician' => ['nullable', 'string', 'max:150'],
            'patient_blood_type_id' => ['nullable', 'integer', 'exists:blood_types,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'patient_surname.required_without' => 'Enter the patient surname, or link the patient\'s transfusion request.',
            'patient_first_name.required_without' => 'Enter the patient first name, or link the patient\'s transfusion request.',
            'patient_age.required_without' => 'Enter the patient age, or link the patient\'s transfusion request.',
            'patient_sex.required_without' => 'Select the patient sex, or link the patient\'s transfusion request.',
        ];
    }
}
