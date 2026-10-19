<?php

namespace App\Http\Requests;

use App\Enums\MarkerResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSerologyResultsRequest extends FormRequest
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
            // The panel as a whole: any reactive marker makes it reactive.
            // awaiting_verification is a reactive panel not yet verified;
            // correction_pending, a panel with a correction waiting.
            'outcome' => ['sometimes', 'nullable', 'string', Rule::in([...MarkerResult::values(), 'awaiting_verification', 'correction_pending'])],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(['newest', 'oldest', 'reactive_first'])],
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
