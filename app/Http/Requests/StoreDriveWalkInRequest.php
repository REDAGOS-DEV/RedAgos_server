<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDriveWalkInRequest extends FormRequest
{
    /**
     * The route's can:appointments.verify ability is the gate, as for any check-in.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The donor, found by the counter's exact lookup.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'donor_uuid' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'donor_uuid.required' => 'Find the donor first.',
        ];
    }
}
