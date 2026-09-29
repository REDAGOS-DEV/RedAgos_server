<?php

namespace App\Http\Requests;

use App\Enums\BloodBagType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordCollectionRequest extends FormRequest
{
    /**
     * The donation being corrected, when CorrectionService validates a
     * corrected box, so the donation's own barcode is not a collision.
     */
    public ?int $correctingDonationId = null;

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

            // The pre-printed barcode sticker: the same number is on the form,
            // every bag and tube, and the CUE slip. Each bag's unit number is
            // built from it (see BagNumbers), which is why it is held to what
            // a unit id may contain and short enough to leave room for the
            // component code. Unique per facility, checked here for a friendly
            // error; the unique index is what holds under a race, and the
            // service turns its violation into the same error.
            'donation_barcode' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Z0-9-]+$/',
                Rule::unique('blood_collections', 'donation_barcode')
                    ->where('facility_id', $this->user()?->facility_id)
                    // A correction keeps its own barcode.
                    ->ignore($this->correctingDonationId, 'donation_id'),
            ],

            // No maximum draw duration: that is a clinical constant nobody has
            // owned. Only the order and "not in the future" are checked.
            'started_at' => ['required', 'date', 'before_or_equal:now'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at', 'before_or_equal:now'],
        ];
    }

    /**
     * Normalise the barcode before it is validated.
     *
     * Barcode scanners append a carriage return or tab, and staff type in
     * whatever case. Without this a scanned and a hand-typed copy of the same
     * sticker would be two different numbers, and uniqueness would mean nothing.
     */
    protected function prepareForValidation(): void
    {
        $barcode = $this->input('donation_barcode');

        if (is_string($barcode)) {
            $this->merge([
                'donation_barcode' => strtoupper((string) preg_replace('/[\s\p{Cc}]+/u', '', $barcode)),
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
            'donation_barcode.required' => 'Scan or type the donation barcode.',
            'donation_barcode.unique' => 'This barcode is already recorded at this facility. Scan the sticker again.',
            'donation_barcode.regex' => 'A donation barcode may contain only letters, numbers and dashes.',
            'donation_barcode.max' => 'A donation barcode is at most 30 characters.',
            'started_at.before_or_equal' => 'A collection cannot start in the future.',
            'ended_at.before_or_equal' => 'A collection cannot end in the future.',
            'ended_at.after_or_equal' => 'The time ended cannot be before the time started.',
        ];
    }
}
