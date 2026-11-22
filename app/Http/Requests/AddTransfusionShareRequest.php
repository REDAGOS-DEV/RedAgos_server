<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddTransfusionShareRequest extends FormRequest
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
     * A centre's share of a Blood Request's unallocated units: which of its
     * lines, and how many of each. The component and indication come from the
     * Blood Request. What is still unallocated is checked under its lock.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:6'],
            'lines.*.transfusion_request_item_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Choose how many units this center will supply.',
            'lines.*.transfusion_request_item_id.distinct' => 'Each component can only be listed once.',
        ];
    }
}
