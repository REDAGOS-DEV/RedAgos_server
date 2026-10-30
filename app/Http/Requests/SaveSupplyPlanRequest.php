<?php

namespace App\Http\Requests;

use App\Enums\HandoverMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSupplyPlanRequest extends FormRequest
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
     * The shape only. What each line may be committed to depends on what it
     * asked for and what is already held for it, so SupplyPlanService judges
     * the figures, and the date against the operational day.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'handover_method' => ['required', Rule::enum(HandoverMethod::class)],
            'scheduled_at' => ['required', 'date'],
            // A delivery needs somewhere to go. A pick-up may name the counter.
            'handover_address' => ['nullable', 'string', 'max:255', 'required_if:handover_method,'.HandoverMethod::Delivery->value],
            'contact_name' => ['nullable', 'string', 'max:150'],
            // A centre's line may be a landline, so not the mobile pattern.
            'contact_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.request_item_id' => ['required', 'integer', 'distinct'],
            'lines.*.committed_quantity' => ['required', 'integer', 'min:0', 'max:1000'],
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
            'handover_method.required' => 'Choose whether the hospital picks the order up or you deliver it.',
            'scheduled_at.required' => 'Set when the order is picked up or delivered.',
            'handover_address.required_if' => 'Enter where the order is delivered to.',
            'contact_phone.regex' => 'Enter a phone number of digits, spaces, +, - or brackets.',
            'lines.required' => 'Say how many units you will supply of each line.',
        ];
    }
}
