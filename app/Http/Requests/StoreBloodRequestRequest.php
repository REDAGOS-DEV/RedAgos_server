<?php

namespace App\Http\Requests;

use App\Enums\UrgencyLevel;
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'target_facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'blood_type_id' => ['required', 'integer', 'exists:blood_types,id'],
            'component_id' => ['required', 'integer', 'exists:blood_components,id'],
            // Upper bound is a sanity guard on a typed figure, not a clinical
            // rule. A request for a thousand units is a slipped keystroke.
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'urgency_level' => ['required', Rule::in(UrgencyLevel::values())],
        ];
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
            'component_id.required' => 'Select the blood component required.',
            'quantity.min' => 'A request must be for at least one unit.',
            'quantity.max' => 'A single request cannot exceed 100 units.',
            'urgency_level.in' => 'Urgency must be either routine or emergency.',
        ];
    }
}
