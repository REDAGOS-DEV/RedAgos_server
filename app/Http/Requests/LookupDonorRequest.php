<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LookupDonorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Exact identifiers only.
     *
     * This is the one path that reaches donors from other facilities, so it
     * accepts a value a person physically presented — a donor card, an ID, an
     * address they typed. A name or partial match here would turn it into a way
     * to enumerate the donor register.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['donor_code', 'email', 'phone', 'valid_id_number'])],
            'value' => ['required', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'Look a donor up by donor code, email, phone or valid ID number.',
        ];
    }
}
