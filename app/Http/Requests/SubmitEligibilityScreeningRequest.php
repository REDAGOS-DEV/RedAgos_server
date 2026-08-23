<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitEligibilityScreeningRequest extends FormRequest
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
            'question_version' => ['required', 'integer', 'min:1'],
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.code' => ['required', 'string', 'max:20', 'distinct'],
            'answers.*.answer' => ['required', 'boolean'],
            'vitals' => ['sometimes', 'array'],
            'vitals.weight' => ['required_with:vitals', 'nullable', 'integer', 'min:20', 'max:400'],
            'vitals.last_donation_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'result' => ['sometimes', 'nullable', 'string', 'in:eligible,not_eligible,deferred'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'answers.required' => 'Please answer every screening question before submitting.',
            'answers.*.answer.required' => 'Please answer every screening question before submitting.',
            'vitals.weight.integer' => 'Please enter your weight in whole kilograms.',
        ];
    }
}
