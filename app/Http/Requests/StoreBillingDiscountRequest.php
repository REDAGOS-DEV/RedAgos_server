<?php

namespace App\Http\Requests;

use App\Models\BillingDiscount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One of the centre's discounts: a name, a percentage or a fixed peso amount,
 * and whether it is offered at the counter.
 */
class StoreBillingDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => preg_replace('/\s+/', ' ', trim($this->input('name')))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'kind' => ['required', Rule::in(BillingDiscount::KINDS)],
            'value' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                $this->input('kind') === BillingDiscount::PERCENTAGE ? 'max:100' : 'max:1000000',
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'value.gt' => 'Enter an amount above zero.',
            'value.max' => $this->input('kind') === BillingDiscount::PERCENTAGE
                ? 'A percentage cannot be more than 100.'
                : 'A discount cannot be more than ₱1,000,000.',
            'value.decimal' => 'Use at most two decimal places.',
        ];
    }
}
