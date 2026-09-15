<?php

namespace App\Http\Requests;

use App\Enums\ScreeningOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordScreeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `recorded_by` and `facility_id` are absent by design: both come from the
     * authenticated staff member, never from the request, because together they
     * are what makes the record traceable.
     *
     * The vital ranges below are sanity bounds on what a human can type, not
     * clinical thresholds. Nothing in this application decides whether a set of
     * vitals qualifies a donor — `outcome` carries the verdict a qualified
     * professional reached. See the clinical-configuration boundary in
     * docs/IMPLEMENTATION_DECISIONS.md.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', Rule::in(ScreeningOutcome::values())],

            // Required only when the donor is turned away: a deferral the donor
            // cannot be given a reason for is not a usable record.
            'deferral_reason' => [
                Rule::requiredIf(fn (): bool => $this->input('outcome') === ScreeningOutcome::Deferred->value),
                'nullable',
                'string',
                'max:255',
            ],

            'systolic_bp' => ['sometimes', 'nullable', 'integer', 'min:40', 'max:300'],
            'diastolic_bp' => ['sometimes', 'nullable', 'integer', 'min:20', 'max:200'],
            'pulse_bpm' => ['sometimes', 'nullable', 'integer', 'min:20', 'max:250'],
            'temperature_c' => ['sometimes', 'nullable', 'numeric', 'min:30', 'max:45'],
            'weight_kg' => ['sometimes', 'nullable', 'integer', 'min:20', 'max:400'],
            'haemoglobin_g_dl' => ['sometimes', 'nullable', 'numeric', 'min:3', 'max:25'],

            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'screened_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'deferral_reason.required' => 'Give the reason the donor was deferred.',
            'screened_at.before_or_equal' => 'A screening cannot be recorded in the future.',
        ];
    }
}
