<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AmendQuestionnaireAnswersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The questionnaire named outright, so a change lands on the document the
     * physician was reading even if the donor has answered again since.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'screening_id' => ['required', 'integer'],
            'answers' => ['required', 'array', 'min:1', 'max:100'],
            'answers.*.code' => ['required', 'string', 'max:20', 'distinct'],
            'answers.*.answer' => ['required', 'boolean'],
        ];
    }
}
