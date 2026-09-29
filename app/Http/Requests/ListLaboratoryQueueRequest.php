<?php

namespace App\Http\Requests;

use App\Enums\DonationStatus;
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
            // its clearance. `testing` is either of the two. Anything else is
            // the queue Processing has always worked.
            'stage' => ['sometimes', 'string', Rule::in(['testing', 'serology', 'immunohematology', 'processing'])],

            // A scanned or typed donation barcode (the sticker on the tube), to
            // find the donation it came from.
            'barcode' => ['sometimes', 'string', 'max:30'],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Normalise a scanned barcode the same way the counter stored it.
     */
    protected function prepareForValidation(): void
    {
        $barcode = $this->input('barcode');

        if (is_string($barcode)) {
            $normalised = strtoupper((string) preg_replace('/[\s\p{Cc}]+/u', '', $barcode));

            $normalised === ''
                ? $this->request->remove('barcode')
                : $this->merge(['barcode' => $normalised]);
        }
    }
}
