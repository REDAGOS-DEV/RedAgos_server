<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProcessedDonationsRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            // Where the processed unit stands: waiting for Processing to hand
            // it over, handed over to Issuance (completed), or rejected; or
            // with a breakdown correction waiting for a decision.
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['awaiting_handover', 'handed_over', 'rejected', 'correction_pending'])],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(['newest', 'oldest'])],
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
