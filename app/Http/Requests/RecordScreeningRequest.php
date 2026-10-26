<?php

namespace App\Http\Requests;

use App\Enums\ScreeningOutcome;
use App\Support\OperationalDay;
use App\Support\ScreeningVitals;
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
     * clinical thresholds. `outcome` carries the verdict a qualified
     * professional reached. The clinical ranges are config
     * (`donation.vitals`): at the counter they decide only which deferral
     * codes may be recorded (ScreeningVitals, CollectionService).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            ...self::fieldRules($this),
            'screened_at' => ['sometimes', 'date', 'before_or_equal:now'],

            // The last day of a temporary deferral, from the counter's
            // deferral form. Dropped for any other outcome.
            'deferred_until' => [
                'exclude_unless:outcome,'.ScreeningOutcome::TemporarilyDeferred->value,
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.OperationalDay::todayAsDate(),
            ],
        ];

        // The counter records all six vital signs before accepting or
        // deferring (project owner, 2026-10-24); their type and bounds stay
        // as fieldRules has them. A drive's paper record keeps them
        // optional: it types what the paper gives.
        foreach (ScreeningVitals::fields() as $field) {
            $rules[$field] = ['required', ...array_values(array_diff($rules[$field] ?? [], ['sometimes', 'nullable']))];
        }

        return $rules;
    }

    /**
     * The screening's own fields, shared with a drive's paper record
     * (RecordPaperDonationRequest) so the two can never accept different
     * values for the same box on the form.
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(FormRequest $request): array
    {
        return [
            'outcome' => ['required', 'string', Rule::in(ScreeningOutcome::values())],

            // Required only when the donor is turned away: a deferral the donor
            // cannot be given a reason for is not a usable record.
            //
            // Asked of the outcome rather than compared to one value, so a
            // permanent or indefinite deferral cannot slip through unexplained
            // the way a single-value compare would have let it.
            //
            // One of the centre's deferral codes (project owner, 2026-10-24):
            // the record and the donor see the code, and the service copies
            // the reason it stands for (DeferralCodeService::chosen()). Or
            // "Other": the reason typed, with no code.
            'deferral_code' => [
                Rule::requiredIf(fn (): bool => (ScreeningOutcome::tryFrom(
                    (string) $request->input('outcome')
                )?->isDeferral() ?? false) && blank($request->input('other_reason'))),
                'nullable',
                'string',
                'max:16',
            ],
            'other_reason' => ['nullable', 'string', 'max:255', 'prohibits:deferral_code'],

            'systolic_bp' => ['sometimes', 'nullable', 'integer', 'min:40', 'max:300'],
            'diastolic_bp' => ['sometimes', 'nullable', 'integer', 'min:20', 'max:200'],
            'pulse_bpm' => ['sometimes', 'nullable', 'integer', 'min:20', 'max:250'],
            'temperature_c' => ['sometimes', 'nullable', 'numeric', 'min:30', 'max:45'],
            'weight_kg' => ['sometimes', 'nullable', 'integer', 'min:20', 'max:400'],
            'haemoglobin_g_dl' => ['sometimes', 'nullable', 'numeric', 'min:3', 'max:25'],

            // Section II's fingerprick typing. Optional and preliminary: it is
            // recorded for reference and never reaches the donor profile.
            'fingerprick_blood_type_id' => ['sometimes', 'nullable', 'integer', 'exists:blood_types,id'],

            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],

            // The donor answered the printed DHQ rather than the onsite one,
            // which is optional (project owner, 2026-10-17).
            'dhq_on_paper' => ['sometimes', 'boolean'],

            // Section I-D. Free text because the form gives ruled blanks and
            // no document defines a vocabulary for any of them. All optional:
            // what a centre records varies, and a blank is more honest than a
            // required field filled with a placeholder.
            'sleep' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meal' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meds' => ['sometimes', 'nullable', 'string', 'max:255'],
            'allergies' => ['sometimes', 'nullable', 'string', 'max:255'],
            'general_appearance' => ['sometimes', 'nullable', 'string', 'max:255'],
            'skin' => ['sometimes', 'nullable', 'string', 'max:255'],
            'heent' => ['sometimes', 'nullable', 'string', 'max:255'],
            'heart_and_lungs' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'deferral_code.required' => 'Choose the deferral code, or Other and type the reason.',
            'other_reason.prohibits' => 'Choose a code or Other, not both.',
            'screened_at.before_or_equal' => 'A screening cannot be recorded in the future.',
            'deferred_until.required' => 'A temporary deferral needs an Until date.',
            'deferred_until.after_or_equal' => 'The Until date must be today or later.',
            ...collect(config('donation.vitals', []))
                ->mapWithKeys(fn (array $rule, string $field): array => [
                    "{$field}.required" => "Record the {$rule['label']} before accepting or deferring the donor.",
                ])
                ->all(),
        ];
    }
}
