<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchCounterDonorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A name, or a donor's email or phone. Two characters at least, so a
     * single letter cannot page through the centre's whole donor list.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.required' => 'Type the donor’s name, email or phone number.',
            'q.min' => 'Type at least two characters of the donor’s name, email or phone number.',
        ];
    }
}
