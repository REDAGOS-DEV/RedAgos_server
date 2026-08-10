<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:walkin,mobile'],
            'center_id' => ['required_if:type,walkin', 'nullable', 'integer', 'exists:facilities,id'],
            'drive_id' => ['required_if:type,mobile', 'nullable', 'integer', 'exists:mobile_events,id'],
            'date' => ['required_if:type,walkin', 'nullable', 'date_format:Y-m-d'],
            'time_slot' => ['required', 'string', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'Choose either a walk-in appointment or a mobile blood drive.',
            'center_id.required_if' => 'Please choose a blood centre.',
            'drive_id.required_if' => 'Please choose a blood drive.',
            'date.required_if' => 'Please choose an appointment date.',
            'time_slot.required' => 'Please choose a time slot.',
            'time_slot.date_format' => 'Please choose a valid time slot.',
        ];
    }
}
