<?php

namespace App\Http\Requests;

use App\Enums\IndicationCode;
use App\Enums\UrgencyLevel;
use App\Enums\ValidIdType;
use Illuminate\Validation\Rule;

/**
 * A walk-in Patient Transfusion request, recorded at a blood centre.
 *
 * Extends the portal's form request for its per-line indication checks —
 * a walk-in carries the same DOH form, ticked the same way. Everything else
 * differs: the hospital is named rather than taken from the caller, the purpose
 * is fixed, and the request cannot be saved without the watcher who presented
 * it. The hospital's phone confirmation is no longer asked for (project owner,
 * 2026-10-19).
 *
 * A continuation names the Patient Transfusion Request it adds this centre's
 * share to and, per line, the requirement line the share answers. Patient,
 * blood type, component and indication then come from the requirement — they
 * were certified once, when it was recorded — so they are only required when
 * a new requirement is being recorded.
 */
class StoreWalkInBloodRequestRequest extends StoreBloodRequestRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $unlessContinuing = 'required_without:transfusion_request_id';

        return [
            // The hospital chosen from the list, or the name of one not on
            // RedAgos (project owner, 2026-10-19). One or the other.
            'hospital_id' => ['required_without:hospital_name', 'nullable', 'integer', 'exists:facilities,id'],
            'hospital_name' => ['required_without:hospital_id', 'nullable', 'string', 'max:150'],
            'transfusion_request_id' => ['nullable', 'integer', 'exists:transfusion_requests,id'],
            'urgency_level' => ['required', Rule::in(UrgencyLevel::values())],

            'patient_surname' => [$unlessContinuing, 'nullable', 'string', 'max:100'],
            'patient_first_name' => [$unlessContinuing, 'nullable', 'string', 'max:100'],
            'patient_middle_name' => ['nullable', 'string', 'max:100'],
            'patient_age' => [$unlessContinuing, 'nullable', 'integer', 'min:0', 'max:130'],
            'patient_sex' => [$unlessContinuing, 'nullable', Rule::in(['male', 'female'])],
            'blood_type_id' => [$unlessContinuing, 'nullable', 'integer', 'exists:blood_types,id'],

            // What the watcher carries from the hospital. All optional.
            'presented_reference' => ['nullable', 'string', 'max:60'],
            'attending_physician' => ['nullable', 'string', 'max:150'],
            'patient_ward' => ['nullable', 'string', 'max:100'],
            'patient_record_number' => ['nullable', 'string', 'max:60'],

            'items' => ['required', 'array', 'min:1', 'max:6'],
            'items.*.component_id' => [$unlessContinuing, 'nullable', 'integer', 'exists:blood_components,id', 'distinct'],
            'items.*.transfusion_request_item_id' => ['required_with:transfusion_request_id', 'nullable', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.indication_code' => ['nullable', Rule::in(IndicationCode::values())],
            'items.*.indication_other' => ['nullable', 'string', 'max:255'],

            // The watcher: who presented the request, never who requested it.
            'representative' => ['required', 'array'],
            'representative.name' => ['required', 'string', 'max:150'],
            'representative.relationship' => ['required', 'string', 'max:60'],
            'representative.contact' => ['required', 'string', 'max:30'],
            'representative.id_type' => ['nullable', Rule::in(ValidIdType::values())],
            'representative.id_number' => ['nullable', 'required_with:representative.id_type', 'string', 'max:50'],

            'duplicate_acknowledgement' => ['nullable', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'hospital_id.required_without' => "Choose the patient's hospital blood bank, or type its name.",
            'hospital_name.required_without' => "Choose the patient's hospital blood bank, or type its name.",
            'patient_surname.required_without' => 'Enter the patient surname.',
            'patient_first_name.required_without' => 'Enter the patient first name.',
            'patient_age.required_without' => 'Enter the patient age.',
            'patient_sex.required_without' => 'Select the patient sex.',
            'blood_type_id.required_without' => 'Select the blood type required.',
            'items.*.component_id.required_without' => 'Select the blood component required.',
            'items.*.transfusion_request_item_id.required_with' => 'Say which component of the patient\'s request this line answers.',
            'items.*.transfusion_request_item_id.distinct' => 'Each component can only be listed once on a request.',
            'representative.name.required' => 'Enter the name of the watcher presenting the request.',
            'representative.relationship.required' => 'Enter how the watcher is related to the patient.',
            'representative.contact.required' => "Enter the watcher's contact number.",
            'representative.id_number.required_with' => 'Enter the ID number of the ID presented.',
            'duplicate_acknowledgement.min' => 'Say why a separate request is needed.',
        ];
    }
}
