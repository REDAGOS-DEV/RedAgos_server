<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Opening a cash shift at the billing counter: the float put in the drawer.
 *
 * The cashier is the caller, never input. A float of zero is allowed — a
 * drawer can start empty — but it must be stated, so the count at close has
 * something to start from.
 */
class OpenCashShiftRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware, as elsewhere in this application.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'opening_float' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'counter_label' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opening_float.required' => 'Enter the cash in the drawer as the shift opens, even if it is zero.',
            'opening_float.decimal' => 'Enter the float in pesos and centavos, with at most two decimal places.',
        ];
    }
}
