<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            // Section I-C. Every statement must be accepted before a
            // questionnaire is recorded, so the payload carries the version the
            // donor read rather than a list of ticked boxes -- the version and
            // its stored hash already pin the exact set of statements shown.
            // consented_at and the hash are computed server-side; a client
            // never gets to say when it consented or to what.
            'consent' => ['required', 'array'],
            'consent.version' => [
                'required', 'string', 'max:20',
                Rule::in(array_keys((array) config('donor_consent.versions', []))),
            ],
            'consent.accepted' => ['required', 'accepted'],

            'vitals' => ['sometimes', 'array'],
            'vitals.weight' => ['required_with:vitals', 'nullable', 'integer', 'min:20', 'max:400'],
            'vitals.last_donation_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            // The one Section I-A field that cannot be derived from records: a
            // previous donation may have been at a non-RedAgos centre.
            'vitals.last_donation_venue' => ['sometimes', 'nullable', 'string', 'max:150'],
            'vitals.last_menstrual_period' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],

            // `result` is deliberately gone. The donor's app no longer scores
            // the answers, so there is no client verdict to record or compare.
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
            'consent.required' => 'Please read and accept the donor consent statements.',
            'consent.accepted.accepted' => 'Please accept every consent statement before submitting.',
        ];
    }
}
