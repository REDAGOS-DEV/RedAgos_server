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
     * between a bag and the account that recorded it. It is the form's
     * "Phlebotomist" unless `phlebotomist_name` names somebody else, for a bag
     * another person drew (project owner, 2026-11-06).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...self::boxRules(
                $this->user()?->facility_id,
                // A donation's own barcode is never a collision. A correction
                // keeps it, and a save retried after its response was lost is
                // answered by the service as already recorded, not as somebody
                // else's bag.
                $this->correctingDonationId ?? $this->route('donation')
            ),
            'phlebotomist_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * The "For Phlebotomist Use Only" box, shared with a drive's paper record
     * (RecordPaperDonationRequest), which nests it under `collection.`.
     *
     * @return array<string, mixed>
     */
    public static function boxRules(mixed $facilityId, mixed $ignoreDonationId = null, string $prefix = ''): array
    {
        return [
            // A whole-blood bag is 450 mL nominal; the range is wide enough for
            // a short draw or a double without asserting a clinical rule nobody
            // has owned. See the clinical-configuration boundary decision.
            $prefix.'volume_ml' => ['required', 'integer', 'min:100', 'max:1000'],

            // The "For Phlebotomist Use Only" box of Section II. Record only:
            // the bag does not limit the component breakdown.
            $prefix.'blood_bag_type' => ['required', 'string', Rule::in(BloodBagType::values())],

            // The pre-printed barcode sticker: the same number is on the form,
            // every bag and tube, and the CUE slip. Each bag's unit number is
            // built from it (see BagNumbers). Digits only (project owner,
            // 2026-11-11), and short enough to leave room for the component
            // code. Unique per facility, checked here for a friendly
            // error; the unique index is what holds under a race, and the
            // service turns its violation into the same error.
            $prefix.'donation_barcode' => [
                'required',
                'string',
                'max:30',
                'regex:/^[0-9]+$/',
                Rule::unique('blood_collections', 'donation_barcode')
                    ->where('facility_id', $facilityId)
                    ->ignore($ignoreDonationId, 'donation_id'),
            ],

            // No maximum draw duration: that is a clinical constant nobody has
            // owned. Only the order and "not in the future" are checked.
            $prefix.'started_at' => ['required', 'date', 'before_or_equal:now'],
            $prefix.'ended_at' => ['required', 'date', 'after_or_equal:'.$prefix.'started_at', 'before_or_equal:now'],
        ];
    }

    /**
     * A scanned or typed barcode as it is stored: scanner control characters
     * and whitespace stripped, uppercased.
     */
    public static function normaliseBarcode(mixed $barcode): mixed
    {
        return is_string($barcode)
            ? strtoupper((string) preg_replace('/[\s\p{Cc}]+/u', '', $barcode))
            : $barcode;
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
        if (is_string($this->input('donation_barcode'))) {
            $this->merge([
                'donation_barcode' => self::normaliseBarcode($this->input('donation_barcode')),
            ]);
        }

        // Here as well as in the middleware: a correction validates through
        // this request without passing through it, and a blank must not count
        // as a name.
        if (is_string($this->input('phlebotomist_name'))) {
            $this->merge(['phlebotomist_name' => trim($this->input('phlebotomist_name')) ?: null]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::boxMessages();
    }

    /**
     * @return array<string, string>
     */
    public static function boxMessages(string $prefix = ''): array
    {
        return [
            $prefix.'blood_bag_type.in' => 'A blood bag is single, double or triple.',
            $prefix.'donation_barcode.required' => 'Scan or type the donation barcode.',
            $prefix.'donation_barcode.unique' => 'This barcode is already recorded at this facility. Scan the sticker again.',
            $prefix.'donation_barcode.regex' => 'A donation barcode may contain only numbers.',
            $prefix.'donation_barcode.max' => 'A donation barcode is at most 30 characters.',
            $prefix.'started_at.before_or_equal' => 'A collection cannot start in the future.',
            $prefix.'ended_at.before_or_equal' => 'A collection cannot end in the future.',
            $prefix.'ended_at.after_or_equal' => 'The time ended cannot be before the time started.',
        ];
    }
}
