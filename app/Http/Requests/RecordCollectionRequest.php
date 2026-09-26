<?php

namespace App\Http\Requests;

use App\Enums\BloodBagType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `collected_by` is absent by design: it is the authenticated staff member,
     * never a name supplied by the request, because it is the traceability link
     * between a bag and the person who drew it. It is the form's "Phlebotomist".
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A whole-blood bag is 450 mL nominal; the range is wide enough for
            // a short draw or a double without asserting a clinical rule nobody
            // has owned. See the clinical-configuration boundary decision.
            'volume_ml' => ['required', 'integer', 'min:100', 'max:1000'],

            // The "For Phlebotomist Use Only" box of Section II. Record only:
            // the bag does not limit the component breakdown.
            'blood_bag_type' => ['required', 'string', Rule::in(BloodBagType::values())],

            // Unique per facility, checked here for a friendly error. The
            // unique index is what actually holds under a race, and the service
            // turns its violation into the same error.
            'segment_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('blood_collections', 'segment_number')
                    ->where('facility_id', $this->user()?->facility_id),
            ],

            // No maximum draw duration: that is a clinical constant nobody has
            // owned. Only the order and "not in the future" are checked.
            'started_at' => ['required', 'date', 'before_or_equal:now'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at', 'before_or_equal:now'],
        ];
    }

    /**
     * Normalise the segment number before it is validated.
     *
     * Barcode scanners append a carriage return or tab, and staff type in
     * whatever case. Without this a scanned and a hand-typed copy of the same
     * tube would be two different numbers, and uniqueness would mean nothing.
     */
    protected function prepareForValidation(): void
    {
        $segment = $this->input('segment_number');

        if (is_string($segment)) {
            $this->merge([
                'segment_number' => strtoupper((string) preg_replace('/[\s\p{Cc}]+/u', '', $segment)),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'blood_bag_type.in' => 'A blood bag is single, double or triple.',
            'segment_number.unique' => 'This segment number is already recorded at this facility. Scan the bag again.',
            'started_at.before_or_equal' => 'A collection cannot start in the future.',
            'ended_at.before_or_equal' => 'A collection cannot end in the future.',
            'ended_at.after_or_equal' => 'The time ended cannot be before the time started.',
        ];
    }
}
