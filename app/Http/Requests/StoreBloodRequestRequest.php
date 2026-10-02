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
     * Replenishment only. A restock order goes to one centre and has no
     * patient; a patient's need is a Patient Transfusion Request, recorded
     * through its own endpoint and split across centres there. The purpose is
     * still required, so a client still sending a transfusion here is told
     * where it goes rather than having it silently turned into a restock.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'target_facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'blood_type_id' => ['required', 'integer', 'exists:blood_types,id'],
            'urgency_level' => ['required', Rule::in(UrgencyLevel::values())],
            'request_purpose' => ['required', Rule::in([RequestPurpose::Replenishment->value])],

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
            $key = $this->linesKey();
            $items = $this->input($key);

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
                            "{$key}.{$index}.indication_code",
                            "Select the indication for {$componentName}."
                        );
                    }

                    continue;
                }

                if ($componentName !== null && ! $code->belongsToComponent($componentName)) {
                    $validator->errors()->add(
                        "{$key}.{$index}.indication_code",
                        "Indication {$code->value} does not apply to {$componentName}."
                    );
                }

                if ($code->triggersReview() && trim((string) ($item['indication_other'] ?? '')) === '') {
                    $validator->errors()->add(
                        "{$key}.{$index}.indication_other",
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
            'request_purpose.in' => "Record a patient's need as a Patient Transfusion Request.",
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
     * The payload key the component lines arrive under.
     *
     * "items" on a blood request; a Patient Transfusion Request's requirement
     * arrives as "lines", beside the allocations that split it.
     */
    protected function linesKey(): string
    {
        return 'items';
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
