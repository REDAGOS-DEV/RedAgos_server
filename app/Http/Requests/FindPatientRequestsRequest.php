<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FindPatientRequestsRequest extends FormRequest
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
     * A POST body, not a query string, so the patient's name stays out of URLs
     * and access logs.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'patient_surname' => ['required', 'string', 'max:100'],
            'patient_first_name' => ['required', 'string', 'max:100'],
            'blood_type_id' => ['nullable', 'integer', 'exists:blood_types,id'],
        ];
    }
}
