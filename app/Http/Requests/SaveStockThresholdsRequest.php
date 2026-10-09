<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStockThresholdsRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `minimum_units` must be present on every cell, and null is a real answer
     * that clears it, so the screen can send the whole grid or only what it
     * changed. The bounds are a typing guard, not clinical guidance: nothing in
     * this application decides what a safe minimum is.
     *
     * A component must still exist and not be retired, because a threshold on a
     * retired one could never be seen or edited again.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'thresholds' => [
                'required',
                'array',
                'min:1',
                'max:200',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $seen = [];

                    foreach ((array) $value as $cell) {
                        $key = ($cell['blood_type_id'] ?? '').':'.($cell['component_id'] ?? '');

                        if (isset($seen[$key])) {
                            $fail('Each blood type and component may appear only once.');

                            return;
                        }

                        $seen[$key] = true;
                    }
                },
            ],
            'thresholds.*' => ['required', 'array'],
            'thresholds.*.blood_type_id' => ['required', 'integer', Rule::exists('blood_types', 'id')],
            'thresholds.*.component_id' => [
                'required',
                'integer',
                Rule::exists('blood_components', 'id')->whereNull('deleted_at'),
            ],
            'thresholds.*.minimum_units' => ['present', 'nullable', 'integer', 'min:1', 'max:9999'],
            'thresholds.*.alerts_enabled' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'thresholds.*.minimum_units.present' => 'Send a minimum, or null to remove it.',
            'thresholds.*.minimum_units.min' => 'A minimum of less than one unit cannot be recorded.',
            'thresholds.*.component_id.exists' => 'That blood component is no longer available.',
        ];
    }
}
