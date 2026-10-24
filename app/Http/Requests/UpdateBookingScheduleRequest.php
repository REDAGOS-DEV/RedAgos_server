<?php

namespace App\Http\Requests;

use App\Service\BookingScheduleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A blood centre's booking days, hours and places (BookingScheduleService).
 */
class UpdateBookingScheduleRequest extends FormRequest
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
            'open_days' => ['required', 'array', 'min:1', 'max:7'],
            'open_days.*' => ['integer', 'between:0,6'],
            'slots_start_at' => ['required', 'date_format:H:i'],
            'slots_end_at' => ['required', 'date_format:H:i', 'after:slots_start_at'],
            'slot_interval_minutes' => ['required', 'integer', Rule::in(BookingScheduleService::INTERVALS)],
            'slot_capacity' => ['required', 'integer', 'between:1,50'],
            'is_accepting_donations' => ['required', 'boolean'],
            'closed_dates' => ['sometimes', 'array', 'max:60'],
            'closed_dates.*.date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'closed_dates.*.reason' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'open_days.min' => 'Pick at least one day the centre takes bookings.',
            'slots_end_at.after' => 'The last slot must start after the first.',
            'closed_dates.*.date.after_or_equal' => 'A closed date must be today or later.',
        ];
    }
}
