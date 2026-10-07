<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A hospital blood bank's weekly request: lines of component, blood type and units.
 *
 * It carries no indication. An indication is a clinician's certification for a
 * patient's transfusion; a weekly request restocks the blood bank's shelves
 * and has no patient to certify.
 *
 * Whether today is a request day for the centre is the service's question, not
 * this one's: it depends on the schedule, which only the database holds.
 */
class StoreWeeklyRequestRequest extends FormRequest
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
            'target_facility_id' => ['required', 'integer', 'exists:facilities,id'],

            // Eight blood types by ten components is the largest request there is.
            'lines' => ['required', 'array', 'min:1', 'max:80'],
            'lines.*.component_id' => ['required', 'integer', 'exists:blood_components,id'],
            'lines.*.blood_type_id' => ['required', 'integer', 'exists:blood_types,id'],
            // A sanity guard on a typed figure, not a clinical rule.
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Refuse the same component and blood type asked for twice.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lines = $this->input('lines');

            if (! is_array($lines)) {
                return;
            }

            $seen = [];

            foreach ($lines as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $key = ((int) ($line['blood_type_id'] ?? 0)).'|'.((int) ($line['component_id'] ?? 0));

                if (isset($seen[$key])) {
                    $validator->errors()->add("lines.{$index}.component_id", 'This component and blood type is already on the request.');
                }

                $seen[$key] = true;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_facility_id.required' => 'Choose the blood center this weekly request is for.',
            'lines.required' => 'Add at least one line to this weekly request.',
            'lines.*.component_id.required' => 'Select the blood component.',
            'lines.*.blood_type_id.required' => 'Select the blood type.',
            'lines.*.quantity.required' => 'Enter the number of units.',
            'lines.*.quantity.min' => 'A line must ask for at least one unit.',
            'lines.*.quantity.max' => 'A single line cannot exceed 100 units.',
        ];
    }
}
