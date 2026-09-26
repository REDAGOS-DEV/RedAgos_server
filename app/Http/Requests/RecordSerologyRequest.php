<?php

namespace App\Http\Requests;

use App\Enums\MarkerResult;
use App\Enums\SerologyMarker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordSerologyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * All five markers, every time. The panel is saved whole: a donation with
     * four readings and a gap is not a tested donation.
     *
     * `confirm_reactive` is a server-side safeguard, not a UI nicety. A
     * reactive marker rejects the donation, permanently defers the donor and
     * refers them for counselling, and none of it can be undone here — so a
     * client bug must not be able to set that off without the medical
     * technologist having confirmed it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (SerologyMarker::cases() as $marker) {
            $rules[$marker->value] = ['required', 'string', Rule::in(MarkerResult::values())];
        }

        $rules['confirm_reactive'] = [
            Rule::requiredIf(fn (): bool => $this->hasReactiveMarker()),
            'nullable',
            'boolean',
            Rule::when($this->hasReactiveMarker(), ['accepted']),
        ];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'confirm_reactive.required' => 'Confirm the reactive result. It rejects the donation, permanently defers the donor and refers them for counselling.',
            'confirm_reactive.accepted' => 'Confirm the reactive result. It rejects the donation, permanently defers the donor and refers them for counselling.',
        ];

        foreach (SerologyMarker::cases() as $marker) {
            $messages["{$marker->value}.required"] = "Record the {$marker->label()} result.";
            $messages["{$marker->value}.in"] = "A {$marker->label()} result is reactive or non-reactive.";
        }

        return $messages;
    }

    private function hasReactiveMarker(): bool
    {
        foreach (SerologyMarker::cases() as $marker) {
            if ($this->input($marker->value) === MarkerResult::Reactive->value) {
                return true;
            }
        }

        return false;
    }
}
