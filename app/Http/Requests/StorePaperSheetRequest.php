<?php

namespace App\Http\Requests;

use App\Models\MobileEvent;
use App\Support\OperationalDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Open a sheet for a drive's paper records: which drive, which day, and the
 * names its rows start with.
 *
 * Any past day is allowed, never a future one (project owner, 2026-10-17):
 * the records may be digitised long after the drive.
 */
class StorePaperSheetRequest extends FormRequest
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
            // A drive planned in RedAgos, at this centre. Otherwise the paper's
            // own name and place for it.
            'mobile_event_id' => [
                'nullable',
                'integer',
                Rule::exists('mobile_events', 'id')
                    ->where('facility_id', $this->user()?->facility_id)
                    ->whereNull('deleted_at'),
            ],
            'drive_name' => ['required_without:mobile_event_id', 'nullable', 'string', 'max:150'],
            'location' => ['nullable', 'string', 'max:255'],

            'held_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.OperationalDay::todayAsDate()],
            'starts_at' => ['required', 'date_format:H:i'],

            'screened_by_name' => ['nullable', 'string', 'max:120'],
            'phlebotomist_name' => ['nullable', 'string', 'max:120'],

            'notify_donors' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mobile_event_id.exists' => 'That drive was not found at your centre.',
            'drive_name.required_without' => 'Name the drive, or pick it from the list.',
            'held_on.before_or_equal' => 'A drive cannot be entered before it has happened.',
            'starts_at.date_format' => 'Give the time the drive started, as HH:MM.',
        ];
    }

    /**
     * A drive planned in RedAgos was held on its own day, so its sheet is
     * dated that day: otherwise its donations would read as given on a day
     * the drive never ran.
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->hasAny(['mobile_event_id', 'held_on']) || ! $this->filled('mobile_event_id')) {
                    return;
                }

                $event = MobileEvent::query()->find((int) $this->input('mobile_event_id'));

                if ($event !== null && $event->event_date->toDateString() !== $this->input('held_on')) {
                    $validator->errors()->add('held_on', 'That drive was held on '.$event->event_date->format('j M Y').'.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['drive_name', 'location', 'screened_by_name', 'phlebotomist_name', 'notes'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim((string) $this->input($key)) ?: null]);
            }
        }
    }
}
