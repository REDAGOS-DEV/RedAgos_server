<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListInventoryActivityRequest extends FormRequest
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
            // One unit's own history, for its details drawer.
            'unit_id' => ['sometimes', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
