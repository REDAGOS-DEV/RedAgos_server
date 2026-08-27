<?php

namespace App\Http\Requests;

use App\Enums\DonationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLaboratoryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the two outcomes this department owns.
     *
     * `tested` is absent because it is reached by recording a result, never by
     * setting a status — so a donation cannot be marked tested without the row
     * that says what was found.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                DonationStatus::Completed->value,
                DonationStatus::Rejected->value,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'The laboratory may only complete or reject a donation.',
        ];
    }
}
