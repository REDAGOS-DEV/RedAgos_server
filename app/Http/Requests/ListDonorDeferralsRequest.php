<?php

namespace App\Http\Requests;

use App\Models\DonorDeferral;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDonorDeferralsRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in([DonorDeferral::ACTIVE, DonorDeferral::EXPIRED, DonorDeferral::LIFTED])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter($this->safe()->except('per_page'), fn ($value): bool => filled($value));
    }
}
