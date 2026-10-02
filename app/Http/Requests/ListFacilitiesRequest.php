<?php

namespace App\Http\Requests;

use App\Enums\FacilityStatus;
use App\Enums\FacilityTypeName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListFacilitiesRequest extends FormRequest
{
    /**
     * Authorisation is the route's job: this endpoint sits behind role:admin.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(FacilityStatus::values())],
            'facility_type' => ['sometimes', 'string', Rule::in(FacilityTypeName::values())],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
