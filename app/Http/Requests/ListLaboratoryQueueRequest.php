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

            // Which department's queue: `testing` is the Testing page's own,
            // anything else is the queue Processing has always worked.
            'stage' => ['sometimes', 'string', Rule::in(['testing', 'processing'])],

            // A scanned or typed tube segment, to find the donation it came from.
            'segment_number' => ['sometimes', 'string', 'max:50'],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Normalise a scanned segment the same way the counter stored it.
     */
    protected function prepareForValidation(): void
    {
        $segment = $this->input('segment_number');

        if (is_string($segment)) {
            $normalised = strtoupper((string) preg_replace('/[\s\p{Cc}]+/u', '', $segment));

            $normalised === ''
                ? $this->request->remove('segment_number')
                : $this->merge(['segment_number' => $normalised]);
        }
    }
}
