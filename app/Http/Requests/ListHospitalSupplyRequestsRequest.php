<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListHospitalSupplyRequestsRequest extends FormRequest
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
     * Other hospitals' open Blood Requests, as a hospital blood bank reads
     * them (2026-11-28): one search over the PTR reference and the requesting
     * hospital's name. Never the patient's name.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
