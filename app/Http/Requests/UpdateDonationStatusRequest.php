<?php

namespace App\Http\Requests;

use App\Enums\DonationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDonationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accepts any declared status; CollectionService is what refuses the ones
     * this department does not own, so the reason can name the laboratory
     * rather than reading as a malformed request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(DonationStatus::values())],
        ];
    }
}
