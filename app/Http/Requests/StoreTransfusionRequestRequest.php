<?php

namespace App\Http\Requests;

use App\Enums\IndicationCode;
use App\Enums\UrgencyLevel;
use Illuminate\Validation\Rule;

/**
 * A Patient Transfusion Request, recorded by the hospital's blood bank.
 *
 * Extends the replenishment form request for its per-line indication checks —
 * the requirement carries the same DOH form, ticked the same way — read here
 * from "lines". What differs is everything around them: the patient is
 * required, staff must confirm their own stock cannot cover the need, and the
 * requirement arrives with the facilities it is split across.
 *
 * How much of each component is asked of which centre is checked against the
 * requirement in TransfusionAllocationWriter, under the requirement's lock,
 * not here: whether a split exceeds what is still unallocated is a question
 * only the database can answer.
 */
class StoreTransfusionRequestRequest extends StoreBloodRequestRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // The hospital's own shelf is checked by hand — RedAgos holds only
            // the bags it delivered there, not the whole shelf — so staff say
            // they did.
            'internal_stock_confirmed' => ['accepted'],

            'blood_type_id' => ['required', 'integer', 'exists:blood_types,id'],
            'urgency_level' => ['required', Rule::in(UrgencyLevel::values())],

            'patient_surname' => ['required', 'string', 'max:100'],
            'patient_first_name' => ['required', 'string', 'max:100'],
            'patient_middle_name' => ['nullable', 'string', 'max:100'],
            // 130 is a sanity guard on a typed figure, not a clinical rule.
            'patient_age' => ['required', 'integer', 'min:0', 'max:130'],
            'patient_sex' => ['required', Rule::in(['male', 'female'])],

            'lines' => ['required', 'array', 'min:1', 'max:6'],
            'lines.*.component_id' => ['required', 'integer', 'exists:blood_components,id', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'lines.*.indication_code' => ['nullable', Rule::in(IndicationCode::values())],
            'lines.*.indication_other' => ['nullable', 'string', 'max:255'],

            ...AddTransfusionAllocationsRequest::shareRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            ...AddTransfusionAllocationsRequest::shareMessages(),
            'internal_stock_confirmed.accepted' => "Confirm the hospital's own stock cannot cover this patient before asking other facilities.",
            'patient_surname.required' => 'Enter the patient surname.',
            'patient_first_name.required' => 'Enter the patient first name.',
            'patient_age.required' => 'Enter the patient age.',
            'patient_sex.required' => 'Select the patient sex.',
            'lines.required' => 'Add at least one blood component the patient needs.',
            'lines.max' => 'A request cannot ask for more than six components.',
            'lines.*.component_id.required' => 'Select the blood component required.',
            'lines.*.component_id.distinct' => 'Each component can only be listed once on a request.',
            'lines.*.quantity.min' => 'A component must be requested in at least one unit.',
            'lines.*.quantity.max' => 'A single component cannot exceed 100 units.',
            'lines.*.indication_code.in' => 'That is not an indication code on the request form.',
        ];
    }

    protected function linesKey(): string
    {
        return 'lines';
    }
}
