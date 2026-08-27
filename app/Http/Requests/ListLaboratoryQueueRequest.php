<?php

namespace App\Http\Requests;

use App\Enums\DonationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListLaboratoryQueueRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in(DonationStatus::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
