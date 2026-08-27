<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
