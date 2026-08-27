<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DeclareComponentsRequest extends FormRequest
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
            // A request-size guard, not a clinical yield rule. How many
            // components one donation may be separated into is a clinical
            // question nobody has answered, and this does not answer it.
            'components' => ['required', 'array', 'min:1', 'max:10'],
            'components.*.component_id' => ['required', 'integer', 'exists:blood_components,id'],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }

    /**
     * Refuse a breakdown that names the same component twice.
     *
     * donation_components carries unique(donation_id, component_id), so a
     * duplicate would collide at the index. Catching it here gives a field
     * error rather than a 500.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ids = array_column((array) $this->input('components', []), 'component_id');

                if (count($ids) !== count(array_unique($ids))) {
                    $validator->errors()->add('components', 'Each component may only be declared once. Combine the quantities instead.');
                }
            },
        ];
    }
}
