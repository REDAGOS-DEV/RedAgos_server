<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectBloodRequestRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware, as elsewhere in this application.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The reason is required, unlike on a requester's own cancellation. The
     * Capstone states that a rejected request records its reason and notifies
     * the requester, and a refusal with no explanation gives the hospital
     * nothing to act on while a patient waits.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'allocation_ids' => ['sometimes', 'array'],
            'allocation_ids.*' => ['integer'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Record why this request cannot be fulfilled.',
            'reason.min' => 'Give a reason the requesting facility can act on.',
        ];
    }
}
