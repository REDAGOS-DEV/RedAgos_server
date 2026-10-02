<?php

namespace App\Http\Requests;

use App\Enums\HospitalUnitStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListHospitalInventoryRequest extends FormRequest
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
        return [
            'status' => ['sometimes', 'string', Rule::in(HospitalUnitStatus::values())],
            'blood_type_id' => ['sometimes', 'integer', 'exists:blood_types,id'],
            'component_id' => ['sometimes', 'integer', 'exists:blood_components,id'],
            'transfusion_request_id' => ['sometimes', 'integer'],
            'expiring_within_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'search' => ['sometimes', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
