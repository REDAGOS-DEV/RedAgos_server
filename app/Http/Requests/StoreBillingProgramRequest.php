<?php

namespace App\Http\Requests;

use App\Models\BillingProgram;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillingProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in([
                BillingProgram::GOVERNMENT_SUBSIDY,
                BillingProgram::PERCENTAGE_DISCOUNT,
                BillingProgram::FIXED_DISCOUNT,
            ])],
            'value' => ['nullable', 'required_unless:kind,government_subsidy', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000000'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'is_enabled' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
