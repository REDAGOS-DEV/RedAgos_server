<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeclareComponentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * One entry per bag the unit was separated into, with the bag's volume.
     *
     * The same component may appear more than once: two bags of Packed RBC
     * are two entries, each with its own volume. Inventory books in exactly
     * one unit per entry.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A request-size guard, not a clinical yield rule. How many
            // bags one donation may be separated into is a clinical question
            // nobody has answered, and this does not answer it.
            'components' => ['required', 'array', 'min:1', 'max:10'],
            'components.*.component_id' => ['required', 'integer', 'exists:blood_components,id'],

            // Sanity bounds on what a person can type, not a clinical range:
            // no rule here says what volume a component should have.
            'components.*.volume_ml' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'components.*.volume_ml.required' => 'Record the volume of each bag.',
            'components.*.volume_ml.min' => 'A bag volume must be at least 1 mL.',
            'components.*.volume_ml.max' => 'A bag volume cannot be more than 1000 mL.',
        ];
    }
}
