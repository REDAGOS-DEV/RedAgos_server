<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LiftDonorDeferralRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
            // Which kind the id is: one recorded on the Deferrals page (the
            // default), or a counter or paper screening's (project owner,
            // 2026-10-25).
            'source' => ['sometimes', 'string', 'in:manual,screening'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the deferral is being lifted.',
        ];
    }
}
