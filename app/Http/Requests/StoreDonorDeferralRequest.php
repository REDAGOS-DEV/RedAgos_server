<?php

namespace App\Http\Requests;

use App\Enums\ScreeningOutcome;
use App\Support\OperationalDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDonorDeferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * From Date defaults to today, as the form fills it in.
     */
    protected function prepareForValidation(): void
    {
        if (blank($this->input('deferred_on'))) {
            $this->merge(['deferred_on' => OperationalDay::todayAsDate()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $temporary = ScreeningOutcome::TemporarilyDeferred->value;
        $today = OperationalDay::todayAsDate();

        return [
            'donor_uuid' => ['required', 'uuid'],
            'outcome' => ['required', 'string', Rule::in([
                $temporary,
                ScreeningOutcome::PermanentlyDeferred->value,
                ScreeningOutcome::IndefiniteDeferral->value,
            ])],
            'reason' => ['required', 'string', 'max:1000'],
            'deferred_on' => ['required', 'date_format:Y-m-d', "before_or_equal:{$today}"],
            // The form says "Ignored for permanent deferrals": dropped, not refused.
            'ends_on' => [
                "exclude_unless:outcome,{$temporary}",
                'required',
                'date_format:Y-m-d',
                "after_or_equal:{$today}",
                'after_or_equal:deferred_on',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'donor_uuid.required' => 'Choose the donor to defer.',
            'reason.required' => 'Give the reason for the deferral.',
            'deferred_on.before_or_equal' => 'A deferral cannot start in the future.',
            'ends_on.required' => 'A temporary deferral needs an Until date.',
            'ends_on.after_or_equal' => 'The Until date must be today or later, and not before the From date.',
        ];
    }
}
