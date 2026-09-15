<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AllocateUnitsRequest extends FormRequest
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
     * Quantity is optional: omitting it means "hold as much as you can", which
     * is what a reviewer approving in full is doing. Whatever is sent, the
     * service caps it at what the request still needs — this bound is only a
     * guard on a typed figure, never the authority on how much may be held.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.min' => 'Allocate at least one unit, or omit the quantity to allocate in full.',
        ];
    }
}
