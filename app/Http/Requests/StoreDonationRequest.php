<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
