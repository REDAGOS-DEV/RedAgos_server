<?php

namespace App\Http\Requests;

use App\Enums\ReferralStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCounsellingReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A referral cannot be moved back to pending, and closing one needs a note:
     * "closed" alone does not say whether the donor was referred, declined, or
     * could never be reached.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    ReferralStatus::Contacted->value,
                    ReferralStatus::Referred->value,
                    ReferralStatus::Closed->value,
                ]),
            ],
            'note' => [
                Rule::requiredIf(fn (): bool => $this->input('status') === ReferralStatus::Closed->value),
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'A referral may be marked contacted, referred or closed.',
            'note.required' => 'Say how the referral ended before closing it.',
        ];
    }
}
