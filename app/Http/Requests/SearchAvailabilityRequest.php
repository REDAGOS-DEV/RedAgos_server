<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchAvailabilityRequest extends FormRequest
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
     * Blood type and component are both required: a search for "any O+" across
     * every component is a different question with a different answer shape,
     * and answering it by accident would show plasma to somebody who needs
     * packed cells.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'blood_type_id' => ['required', 'integer', 'exists:blood_types,id'],
            'component_id' => ['required', 'integer', 'exists:blood_components,id'],
            // Optional: a requester may browse depth before knowing how many
            // units they need. When given it only shapes the answer, and never
            // filters a facility out — a facility that can cover part of an
            // urgent ask is still worth showing.
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
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
            'blood_type_id.required' => 'Select the blood type you are searching for.',
            'component_id.required' => 'Select the blood component you are searching for.',
            'quantity.min' => 'A request must be for at least one unit.',
        ];
    }
}
