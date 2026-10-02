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
     * request_item_id is optional for the same reason and narrows the hold to
     * one component of the form. Only its shape is checked here; that the line
     * belongs to this request is settled in the service, which has the request
     * under lock and would otherwise be trusting an id from input.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'request_item_id' => ['sometimes', 'integer'],
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
            'request_item_id.integer' => 'That is not a component on this request.',
        ];
    }
}
