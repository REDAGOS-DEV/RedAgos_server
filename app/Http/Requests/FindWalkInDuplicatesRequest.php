<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FindWalkInDuplicatesRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware, as elsewhere in this application.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Sent as a POST body rather than a query string so the patient's name
     * stays out of URLs and access logs. Either a reference number or a full
     * name is enough to look; the repository returns nothing for neither.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'hospital_id' => ['required', 'integer', 'exists:facilities,id'],
            'patient_surname' => ['required_without:presented_reference', 'nullable', 'string', 'max:100'],
            'patient_first_name' => ['required_without:presented_reference', 'nullable', 'string', 'max:100'],
            'blood_type_id' => ['nullable', 'integer', 'exists:blood_types,id'],
            'presented_reference' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hospital_id.required' => "Choose the patient's hospital blood bank.",
            'patient_surname.required_without' => 'Enter the patient surname, or the reference number the watcher carries.',
            'patient_first_name.required_without' => 'Enter the patient first name, or the reference number the watcher carries.',
        ];
    }
}
