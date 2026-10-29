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
            // A status, or `tagged` for both tag stages at once.
            'status' => ['sometimes', 'string', Rule::in([...HospitalUnitStatus::values(), 'tagged'])],
            'blood_type_id' => ['sometimes', 'integer', 'exists:blood_types,id'],
            'component_id' => ['sometimes', 'integer', 'exists:blood_components,id'],
            'transfusion_request_id' => ['sometimes', 'integer'],
            'expiring_within_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'search' => ['sometimes', 'string', 'max:50'],
            // First-expiring-first (the default), or the newest arrivals first.
            'sort' => ['sometimes', 'string', Rule::in(['fefo', 'recent'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
