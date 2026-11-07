<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DispatchOrderRequest extends FormRequest
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
     * The bags going out, which are what a replenishment order's dispatch
     * records. Only their shape is checked here; the service judges each one
     * under a lock.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'unit_ids' => ['required', 'array', 'min:1', 'max:500'],
            'unit_ids.*' => ['required', 'string', 'max:64', 'distinct'],
            // Who physically took them: the hospital's collector, or the driver.
            'handed_to' => ['sometimes', 'nullable', 'string', 'max:150'],
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
            'unit_ids.required' => 'Pick the units going out.',
            'unit_ids.min' => 'Pick the units going out.',
        ];
    }
}
