<?php

namespace App\Http\Requests;

use App\Enums\DonationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The facility is never accepted here: it comes from the authenticated
     * staff member, so a donation cannot be filed against another centre.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'donor_uuid' => ['required', 'uuid'],
            'appointment_id' => ['sometimes', 'nullable', 'integer'],

            // Walk-in, TS or NTS, picked at the counter; a walk-in when left out.
            'donation_type' => ['sometimes', Rule::enum(DonationType::class)],
        ];
    }
}
