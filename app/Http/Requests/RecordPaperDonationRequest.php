<?php

namespace App\Http\Requests;

use App\Enums\ScreeningOutcome;
use App\Models\PaperDonationSheet;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * One donor's paper record from a drive: the screening, and the bag when one
 * was drawn.
 *
 * The screening fields and the phlebotomist box are held to exactly the rules
 * the counter's own requests apply (RecordScreeningRequest::fieldRules(),
 * RecordCollectionRequest::boxRules()), so a paper record can never carry a
 * value the counter would refuse. What is added is what a paper record has and
 * a counter one does not: the names as written, and times that must fall on
 * the drive's day rather than today.
 */
class RecordPaperDonationRequest extends FormRequest
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
        $rules = [
            'donor_uuid' => ['required', 'uuid'],

            // The physician as the paper names them.
            'screened_by_name' => ['required', 'string', 'max:120'],

            // Optional: the paper may give no screening time, and the visit is
            // then dated at the drive's opening.
            'screened_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],

            ...RecordScreeningRequest::fieldRules($this),

            // Accepted, but the draw did not go ahead or did not finish.
            'draw_failed' => ['sometimes', 'boolean'],
            'draw_failed_reason' => [
                Rule::requiredIf(fn (): bool => $this->accepted() && $this->boolean('draw_failed')),
                'nullable',
                'string',
                'max:255',
            ],

            'collection' => [
                Rule::requiredIf(fn (): bool => $this->expectsCollection()),
                Rule::prohibitedIf(fn (): bool => ! $this->expectsCollection()),
                'array',
            ],

            // Drawn, but the bag will never reach the laboratory: lost or
            // broken on the way back, or an old record whose bag is long gone.
            'not_to_lab' => ['sometimes', 'boolean'],
            'not_to_lab_reason' => [
                Rule::requiredIf(fn (): bool => $this->expectsCollection() && $this->boolean('not_to_lab')),
                'nullable',
                'string',
                'max:255',
            ],

            // Sent again after staff confirmed a donation too close to another.
            'confirm_interval' => ['sometimes', 'boolean'],
        ];

        if ($this->expectsCollection()) {
            $rules = [
                ...$rules,
                ...RecordCollectionRequest::boxRules($this->user()?->facility_id, null, 'collection.'),
                'collection.phlebotomist_name' => ['required', 'string', 'max:120'],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...RecordCollectionRequest::boxMessages('collection.'),
            'deferral_reason.required' => 'Give the reason the donor was deferred.',
            'screened_by_name.required' => 'Give the name of the physician who screened the donor.',
            'collection.required' => 'Fill in the phlebotomist box, or mark the draw as not completed.',
            'collection.prohibited' => 'A donor who was not drawn has no phlebotomist box.',
            'collection.phlebotomist_name.required' => 'Give the name of the phlebotomist.',
            'draw_failed_reason.required' => 'Say why the draw was not completed.',
            'not_to_lab_reason.required' => 'Say why the bag will not reach the laboratory.',
            'screened_at.before_or_equal' => 'A screening cannot be recorded in the future.',
        ];
    }

    /**
     * Every time on the record falls on the drive's day.
     *
     * The paper gives clock times only; the date is the sheet's. A time that
     * lands on another day is a typing slip, and letting it through would date
     * a draw outside the drive it came from.
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $sheet = PaperDonationSheet::query()
                    ->where('id', (int) $this->route('sheet'))
                    ->where('facility_id', $this->user()?->facility_id)
                    ->first();

                // The service answers a sheet that is not this centre's.
                if ($sheet === null) {
                    return;
                }

                $day = $sheet->held_on->toDateString();

                foreach (['screened_at', 'collection.started_at', 'collection.ended_at'] as $key) {
                    if ($validator->errors()->has($key) || ! $this->filled($key)) {
                        continue;
                    }

                    try {
                        $at = CarbonImmutable::parse((string) $this->input($key));
                    } catch (Throwable) {
                        continue;
                    }

                    if (OperationalDay::dateOf($at) !== $day) {
                        $validator->errors()->add($key, 'This time must fall on the drive\'s day, '.$sheet->held_on->format('j M Y').'.');
                    }
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $collection = $this->input('collection');

        if (is_array($collection) && array_key_exists('donation_barcode', $collection)) {
            $collection['donation_barcode'] = RecordCollectionRequest::normaliseBarcode($collection['donation_barcode']);
        }

        if (is_array($collection) && is_string($collection['phlebotomist_name'] ?? null)) {
            $collection['phlebotomist_name'] = trim($collection['phlebotomist_name']);
        }

        $this->merge([
            ...(is_array($collection) ? ['collection' => $collection] : []),
            ...(is_string($this->input('screened_by_name')) ? ['screened_by_name' => trim((string) $this->input('screened_by_name'))] : []),
        ]);
    }

    private function accepted(): bool
    {
        return ScreeningOutcome::tryFrom((string) $this->input('outcome'))?->permitsCollection() ?? false;
    }

    /**
     * Whether this record must carry a phlebotomist box: accepted, and drawn.
     */
    private function expectsCollection(): bool
    {
        return $this->accepted() && ! $this->boolean('draw_failed');
    }
}
