<?php

namespace App\Http\Requests;

use App\Enums\TestResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordTestResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `recorded_by` is absent by design: it is the authenticated staff member,
     * never a name supplied by the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'result' => ['required', 'string', Rule::in(TestResult::values())],
            'blood_type_id' => ['required', 'integer', 'exists:blood_types,id'],
            'tested_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tested_at.before_or_equal' => 'A screening result cannot be dated in the future.',
            'result.in' => 'A screening result is passed, reactive or inconclusive.',
        ];
    }
}
