<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelMobileEventRequest extends FormRequest
{
    /**
     * The route's can:drives.manage ability is the gate.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The reason is required: it is what the booked donors are told.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason. The booked donors are told it.',
        ];
    }
}
