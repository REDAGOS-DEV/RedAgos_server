<?php

namespace App\Http\Requests;

use App\Enums\DonationStatus;
use App\Support\BagNumbers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListLaboratoryQueueRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in(DonationStatus::values())],

            // Which department's queue. `serology` and `immunohematology` are
            // each laboratory department's own — donations still waiting on
            // its clearance. `testing` is either of the two. `processing` is
            // what still has no component breakdown; a processed unit is on
            // the Processed list instead.
            'stage' => ['sometimes', 'string', Rule::in(['testing', 'serology', 'immunohematology', 'processing'])],

            // A scanned or typed donation barcode (the sticker on the tube),
            // or a bag number typed in full (SNBC-21627990-PRBC), to find the
            // donation it came from. Long enough for a full bag number.
            'barcode' => ['sometimes', 'string', 'max:60'],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Normalise a scanned barcode the same way the counter stored it, and
     * read a full bag number as the sticker inside it. A bag number from
     * another centre names none of this centre's donations, so it is kept as
     * typed and finds nothing.
     */
    protected function prepareForValidation(): void
    {
        $barcode = $this->input('barcode');

        if (is_string($barcode)) {
            $normalised = BagNumbers::normalise($barcode);

            if ($normalised === '') {
                $this->request->remove('barcode');

                return;
            }

            $facilityId = $this->user()?->facility_id;
            $sticker = BagNumbers::barcodeFrom($normalised, $facilityId === null ? null : BagNumbers::centreOf((int) $facilityId));

            $this->merge(['barcode' => $sticker ?? $normalised]);
        }
    }
}
