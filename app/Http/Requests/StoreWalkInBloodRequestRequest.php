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
 * it and the hospital staff member who confirmed it by phone.
 *
 * A follow-up names its parent request and, per line, the parent line it
 * carries. Patient, blood type, component and indication then come from the
 * parent — they were certified once, on the original — so they are only
 * required when there is no parent.
 */
class StoreWalkInBloodRequestRequest extends StoreBloodRequestRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $unlessFollowUp = 'required_without:parent_request_id';

        return [
            'hospital_id' => ['required', 'integer', 'exists:facilities,id'],
            'parent_request_id' => ['nullable', 'integer', 'exists:blood_requests,id'],
            'urgency_level' => ['required', Rule::in(UrgencyLevel::values())],

            'patient_surname' => [$unlessFollowUp, 'nullable', 'string', 'max:100'],
            'patient_first_name' => [$unlessFollowUp, 'nullable', 'string', 'max:100'],
            'patient_middle_name' => ['nullable', 'string', 'max:100'],
            'patient_age' => [$unlessFollowUp, 'nullable', 'integer', 'min:0', 'max:130'],
            'patient_sex' => [$unlessFollowUp, 'nullable', Rule::in(['male', 'female'])],
            'blood_type_id' => [$unlessFollowUp, 'nullable', 'integer', 'exists:blood_types,id'],

            // What the watcher carries from the hospital. All optional.
            'presented_reference' => ['nullable', 'string', 'max:60'],
            'attending_physician' => ['nullable', 'string', 'max:150'],
            'patient_ward' => ['nullable', 'string', 'max:100'],
            'patient_record_number' => ['nullable', 'string', 'max:60'],

            'items' => ['required', 'array', 'min:1', 'max:6'],
            'items.*.component_id' => [$unlessFollowUp, 'nullable', 'integer', 'exists:blood_components,id', 'distinct'],
            'items.*.parent_item_id' => ['required_with:parent_request_id', 'nullable', 'integer', 'distinct'],
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

            // The hospital's confirmation. A walk-in exists only once the
            // hospital has said yes, so `confirmed` must be true and the person
            // who said it must be named.
            'verification' => ['required', 'array'],
            'verification.confirmed' => ['accepted'],
            'verification.verifier_name' => ['required', 'string', 'max:150'],
            'verification.verifier_position' => ['required', 'string', 'max:100'],
            'verification.verifier_contact' => ['required', 'string', 'max:30'],
            // A few minutes' grace for a counter PC whose clock runs ahead.
            'verification.verified_at' => ['required', 'date', 'before_or_equal:+5 minutes'],
            'verification.notes' => ['nullable', 'string', 'max:500'],

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
            'hospital_id.required' => "Choose the patient's hospital blood bank.",
            'patient_surname.required_without' => 'Enter the patient surname.',
            'patient_first_name.required_without' => 'Enter the patient first name.',
            'patient_age.required_without' => 'Enter the patient age.',
            'patient_sex.required_without' => 'Select the patient sex.',
            'blood_type_id.required_without' => 'Select the blood type required.',
            'items.*.component_id.required_without' => 'Select the blood component required.',
            'items.*.parent_item_id.required_with' => 'Say which component of the original request this line carries.',
            'items.*.parent_item_id.distinct' => 'Each component can only be listed once on a request.',
            'representative.name.required' => 'Enter the name of the watcher presenting the request.',
            'representative.relationship.required' => 'Enter how the watcher is related to the patient.',
            'representative.contact.required' => "Enter the watcher's contact number.",
            'representative.id_number.required_with' => 'Enter the ID number of the ID presented.',
            'verification.confirmed.accepted' => 'A walk-in request can only be recorded once the hospital blood bank has confirmed it.',
            'verification.verifier_name.required' => 'Enter the name of the hospital staff member who confirmed the request.',
            'verification.verifier_position.required' => 'Enter their position.',
            'verification.verifier_contact.required' => 'Enter the number you called.',
            'verification.verified_at.required' => 'Enter when the hospital confirmed the request.',
            'verification.verified_at.before_or_equal' => 'The confirmation cannot be in the future.',
            'duplicate_acknowledgement.min' => 'Say why a separate request is needed.',
        ];
    }
}
