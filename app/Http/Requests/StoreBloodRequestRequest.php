<?php

namespace App\Http\Requests;

use App\Enums\IndicationCode;
use App\Enums\RequestPurpose;
use App\Enums\UrgencyLevel;
use App\Models\BloodComponent;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBloodRequestRequest extends FormRequest
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
     * The requesting facility is deliberately absent: it comes from the
     * authenticated user, never from input, so there is nothing here that could
     * let a blood bank raise a request in another's name.
     *
     * Whether the target may actually receive requests is settled in the
     * service, not here — "exists" and "eligible" are different questions, and
     * the eligibility rule lives in one place so widening it later is one edit.
     *
     * Patient fields are required only for a transfusion. A replenishment order
     * restocks the requester's own shelves and has no patient to name, so the
     * columns are nullable in the schema and the obligation is expressed here,
     * where the purpose is known.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $forPatient = 'required_if:request_purpose,'.RequestPurpose::PatientTransfusion->value;

        return [
            'target_facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'blood_type_id' => ['required', 'integer', 'exists:blood_types,id'],
            'urgency_level' => ['required', Rule::in(UrgencyLevel::values())],
            'request_purpose' => ['required', Rule::in(RequestPurpose::values())],

            'patient_surname' => [$forPatient, 'nullable', 'string', 'max:100'],
            'patient_first_name' => [$forPatient, 'nullable', 'string', 'max:100'],
            'patient_middle_name' => ['nullable', 'string', 'max:100'],
            // 130 is a sanity guard on a typed figure, not a clinical rule.
            'patient_age' => [$forPatient, 'nullable', 'integer', 'min:0', 'max:130'],
            'patient_sex' => [$forPatient, 'nullable', Rule::in(['male', 'female'])],

            // Six components exist and a component may be ticked once, so six
            // lines is the most a form can carry.
            'items' => ['required', 'array', 'min:1', 'max:6'],
            'items.*.component_id' => ['required', 'integer', 'exists:blood_components,id', 'distinct'],
            // Upper bound is a sanity guard on a typed figure, not a clinical
            // rule. A request for a thousand units is a slipped keystroke.
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.indication_code' => ['nullable', Rule::in(IndicationCode::values())],
            'items.*.indication_other' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Apply the rules that need one field read against another.
     *
     * Three checks live here rather than in rules(), because each needs the
     * component row to answer. A code existing and a code belonging to the
     * component it was claimed against are different questions. An indication
     * is obligatory, but only for the six components the DOH form prints codes
     * for — a component the form does not cover has no box to tick, and
     * demanding one would make it unrequestable. And an "Others" code obliges
     * the requester to write down what the indication was, since the form says
     * those trigger a review, which cannot happen against a blank.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('items');

            if (! is_array($items)) {
                return;
            }

            $componentNames = $this->componentNames($items);

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $componentName = $componentNames[$item['component_id'] ?? null] ?? null;
                $code = IndicationCode::tryFrom((string) ($item['indication_code'] ?? ''));

                if ($code === null) {
                    if ($componentName !== null && IndicationCode::forComponentName($componentName) !== []) {
                        $validator->errors()->add(
                            "items.{$index}.indication_code",
                            "Select the indication for {$componentName}."
                        );
                    }

                    continue;
                }

                if ($componentName !== null && ! $code->belongsToComponent($componentName)) {
                    $validator->errors()->add(
                        "items.{$index}.indication_code",
                        "Indication {$code->value} does not apply to {$componentName}."
                    );
                }

                if ($code->triggersReview() && trim((string) ($item['indication_other'] ?? '')) === '') {
                    $validator->errors()->add(
                        "items.{$index}.indication_other",
                        "Indication {$code->value} requires you to specify the reason."
                    );
                }
            }
        });
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_facility_id.required' => 'Choose the facility this request is being sent to.',
            'blood_type_id.required' => 'Select the blood type required.',
            'urgency_level.in' => 'Urgency must be either routine or emergency.',
            'request_purpose.required' => 'Say whether this request is for a patient or to replenish stock.',
            'request_purpose.in' => 'A request is either for a patient transfusion or for replenishment.',
            'patient_surname.required_if' => 'Enter the patient surname.',
            'patient_first_name.required_if' => 'Enter the patient first name.',
            'patient_age.required_if' => 'Enter the patient age.',
            'patient_sex.required_if' => 'Select the patient sex.',
            'items.required' => 'Add at least one blood component to this request.',
            'items.max' => 'A request cannot ask for more than six components.',
            'items.*.component_id.required' => 'Select the blood component required.',
            'items.*.component_id.distinct' => 'Each component can only be listed once on a request.',
            'items.*.quantity.min' => 'A component must be requested in at least one unit.',
            'items.*.quantity.max' => 'A single component cannot exceed 100 units.',
            'items.*.indication_code.in' => 'That is not an indication code on the request form.',
        ];
    }

    /**
     * Resolve the names of the components named in the payload, keyed by id.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, string>
     */
    private function componentNames(array $items): array
    {
        $ids = array_filter(array_map(
            fn ($item) => is_array($item) ? ($item['component_id'] ?? null) : null,
            $items
        ));

        if ($ids === []) {
            return [];
        }

        return BloodComponent::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();
    }
}
