<?php

namespace App\Http\Requests;

use App\Enums\BloodUnitStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInventoryRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in(BloodUnitStatus::values())],
            'blood_type_id' => ['sometimes', 'integer', 'exists:blood_types,id'],
            'component_id' => ['sometimes', 'integer', 'exists:blood_components,id'],
            'storage_location' => ['sometimes', 'string', 'max:100'],
            'donation_id' => ['sometimes', 'integer'],
            'expiring_within_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'search' => ['sometimes', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
