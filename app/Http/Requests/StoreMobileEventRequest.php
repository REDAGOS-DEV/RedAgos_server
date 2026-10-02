<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMobileEventRequest extends FormRequest
{
    /**
     * The route's can:drives.manage ability is the gate, as it is for every
     * other blood centre request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Note what is absent: facility_id and created_by. Both are resolved from
     * the access token in the service, so a blood centre cannot schedule a
     * drive in another centre's name by adding a field to the payload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'location' => ['required', 'string', 'max:150'],
            'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'max_capacity' => ['nullable', 'integer', 'min:1'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'assigned_staff' => ['nullable', 'string', 'max:255'],
            'announcement' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Please give this drive a name.',
            'location.required' => 'Please enter the venue for this drive.',
            'event_date.required' => 'Please choose a date for this drive.',
            'event_date.after_or_equal' => 'A drive cannot be scheduled in the past.',
            'max_capacity.min' => 'Capacity must be at least one donor.',
            'end_time.after' => 'The end time must be later than the start time.',
        ];
    }
}
