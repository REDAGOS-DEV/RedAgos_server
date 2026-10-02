<?php

namespace App\Http\Requests;

use App\Enums\ReferralStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCounsellingReferralsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `open` (the default) is every referral not yet closed; `all` is everything.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in([...ReferralStatus::values(), 'open', 'all'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
