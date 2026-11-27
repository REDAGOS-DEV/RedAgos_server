<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupplyTransfusionRequestRequest extends FormRequest
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
     * The bags this hospital sends to another hospital's Blood Request, by
     * bag number. What each bag may be is checked against the shelf.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'unit_ids' => ['required', 'array', 'min:1', 'max:50'],
            'unit_ids.*' => ['required', 'string', 'max:50', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit_ids.required' => 'Pick at least one bag to send.',
            'unit_ids.min' => 'Pick at least one bag to send.',
        ];
    }
}
