<?php

namespace App\Http\Requests;

use App\Enums\FacilityDonorStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDonorsRequest extends FormRequest
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
            'search' => ['sometimes', 'string', 'max:150'],
            'blood_type_id' => ['sometimes', 'integer', 'exists:blood_types,id'],
            'status' => ['sometimes', 'string', Rule::in(FacilityDonorStatus::values())],
            'sort' => ['sometimes', 'string', Rule::in(['newest', 'oldest', 'name'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
