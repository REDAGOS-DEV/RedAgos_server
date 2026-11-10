<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One of the centre's discounts, chosen at the counter, with the note that
 * justifies it, such as the Senior Citizen or PWD ID number (project owner,
 * 2026-11-10: a note is required). Whether the discount is the centre's own
 * and switched on is checked by the service, under the bill's lock.
 */
class ApplyDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('note'))) {
            $this->merge(['note' => trim($this->input('note'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'discount_id' => ['required', 'integer'],
            'note' => ['required', 'string', 'min:2', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.required' => 'Write a note for the discount, such as the ID number.',
        ];
    }
}
