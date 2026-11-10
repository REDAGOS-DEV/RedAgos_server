<?php

namespace App\Http\Requests;

/**
 * The whole discount as the edit dialog sends it, its on/off switch included.
 */
class UpdateBillingDiscountRequest extends StoreBillingDiscountRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'is_active' => ['required', 'boolean'],
        ];
    }
}
