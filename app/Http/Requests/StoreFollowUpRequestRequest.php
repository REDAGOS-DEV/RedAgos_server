<?php

namespace App\Http\Requests;

use App\Enums\UrgencyLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFollowUpRequestRequest extends FormRequest
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
     * A follow-up asks another facility for what the original request could
     * not get. Only where, how urgently and how much are taken: the patient,
     * blood type, components and indications are copied from the original,
     * which is what a physician certified.
     *
     * Only the shape of each quantity is checked here. How much of a line may
     * still be sourced elsewhere is settled in the service, with the original
     * request locked.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'target_facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'urgency_level' => ['sometimes', Rule::in(UrgencyLevel::values())],
            'items' => ['required', 'array', 'min:1', 'max:6'],
            'items.*.parent_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_facility_id.required' => 'Choose the facility to source the rest from.',
            'items.required' => 'Choose at least one component to source elsewhere.',
            'items.*.parent_item_id.distinct' => 'Each component can only be listed once on a request.',
            'items.*.quantity.min' => 'Source at least one unit.',
        ];
    }
}
