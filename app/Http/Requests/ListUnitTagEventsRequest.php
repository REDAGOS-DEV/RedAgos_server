<?php

namespace App\Http\Requests;

use App\Enums\UnitTagStatus;
use App\Enums\UntagReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListUnitTagEventsRequest extends FormRequest
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
            // Ended tags only: an active tag is shown on its bag, not here.
            'status' => ['sometimes', 'string', Rule::in([
                UnitTagStatus::Transfused->value,
                UnitTagStatus::UntaggedAssigned->value,
                UnitTagStatus::UntaggedCrossmatched->value,
            ])],
            'untag_reason' => ['sometimes', 'string', Rule::in(UntagReason::values())],
            'search' => ['sometimes', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
