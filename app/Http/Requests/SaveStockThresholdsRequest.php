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
     * Each cell carries `minimum_units` (the critical level), `par_units`, or
     * both, and a level it leaves out keeps its saved value. That is what lets
     * the screen save its PAR and critical tables apart. Null is a real answer
     * that clears a level. The bounds are a typing guard, not clinical
     * guidance: nothing in this application decides what a safe level is.
     * Whether the critical level sits at or under PAR depends on the saved
     * values too, so the service judges that.
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
            'thresholds.*' => [
                'required',
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_array($value) && ! array_key_exists('minimum_units', $value) && ! array_key_exists('par_units', $value)) {
                        $fail('Send a critical level or a PAR level, or null to remove one.');
                    }
                },
            ],
            'thresholds.*.blood_type_id' => ['required', 'integer', Rule::exists('blood_types', 'id')],
            'thresholds.*.component_id' => [
                'required',
                'integer',
                Rule::exists('blood_components', 'id')->whereNull('deleted_at'),
            ],
            'thresholds.*.minimum_units' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999'],
            'thresholds.*.par_units' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999'],
            'thresholds.*.alerts_enabled' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'thresholds.*.minimum_units.min' => 'A critical level of less than one unit cannot be recorded.',
            'thresholds.*.par_units.min' => 'A PAR level of less than one unit cannot be recorded.',
            'thresholds.*.component_id.exists' => 'That blood component is no longer available.',
        ];
    }
}
