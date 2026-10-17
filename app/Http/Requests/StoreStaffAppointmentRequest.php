<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A booking the desk takes for a donor on the phone. The date is the drive's
 * when one is chosen, so it is only required for the centre itself.
 */
class StoreStaffAppointmentRequest extends FormRequest
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
            'donor_uuid' => ['required', 'uuid'],
            'drive_id' => ['sometimes', 'nullable', 'integer'],
            'date' => ['required_without:drive_id', 'nullable', 'date_format:Y-m-d'],
            'time_slot' => ['required', 'date_format:H:i'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'donor_uuid.required' => 'Choose the donor.',
            'date.required_without' => 'Choose the date.',
            'time_slot.required' => 'Choose a time slot.',
        ];
    }
}
