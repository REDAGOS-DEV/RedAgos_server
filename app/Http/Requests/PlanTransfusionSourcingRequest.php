<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A requirement not yet recorded, sent to be matched against the network.
 *
 * A POST body rather than a query string, like the rest of the request form's
 * lookups, so it can carry the component lines.
 */
class PlanTransfusionSourcingRequest extends FormRequest
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
            'blood_type_id' => ['required', 'integer', 'exists:blood_types,id'],
            'lines' => ['required', 'array', 'min:1', 'max:6'],
            'lines.*.component_id' => ['required', 'integer', 'exists:blood_components,id', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'blood_type_id.required' => 'Select the blood type required.',
            'lines.required' => 'Add at least one blood component the patient needs.',
            'lines.*.component_id.distinct' => 'Each component can only be listed once on a request.',
        ];
    }
}
