<?php

namespace App\Http\Requests;

use App\Support\OperationalDay;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBloodUnitRequest extends FormRequest
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
            'storage_location' => ['sometimes', 'nullable', 'string', 'max:100'],

            // The identical rule intake uses, which is what makes the reinstate
            // path safe: an expired unit can only come back with a date that is
            // not already past.
            'expiry_date' => ['sometimes', 'date', 'after_or_equal:'.OperationalDay::todayAsDate()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expiry_date.after_or_equal' => 'An expiry date that has already passed cannot be recorded.',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if (! $this->hasAny(['storage_location', 'expiry_date'])) {
                    $validator->errors()->add('expiry_date', 'Send a storage location or an expiry date to update.');
                }
            },
        ];
    }
}
