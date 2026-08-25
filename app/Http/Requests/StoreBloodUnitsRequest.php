<?php

namespace App\Http\Requests;

use App\Support\OperationalDay;
use Illuminate\Foundation\Http\FormRequest;

class StoreBloodUnitsRequest extends FormRequest
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
            'donation_id' => ['required', 'integer'],

            // A request-size guard, NOT a clinical yield rule. How many
            // components one donation may be separated into is a clinical
            // question nobody has answered, and this does not answer it.
            'units' => ['required', 'array', 'min:1', 'max:10'],

            'units.*.component_id' => ['required', 'integer', 'exists:blood_components,id'],
            'units.*.storage_location' => ['nullable', 'string', 'max:100'],

            // Checked here and caught again at the index: this is check-then-
            // insert, so a concurrent intake can still take the id in between.
            'units.*.unit_id' => ['nullable', 'string', 'max:50', 'unique:blood_units,id'],

            // after_or_equal, not after: a bag stamped with today's date is
            // usable for the rest of today, and the sweep only expires
            // expiry_date < today. Against OperationalDay rather than the
            // literal 'today', which resolves through PHP's ambient timezone
            // and would disagree with the sweep for eight hours a day on a
            // UTC-configured clone.
            'units.*.expiry_date' => ['required', 'date', 'after_or_equal:'.OperationalDay::todayAsDate()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'units.*.expiry_date.after_or_equal' => 'An expiry date that has already passed cannot be recorded.',
            'units.*.unit_id.unique' => 'This unit number has already been recorded.',
        ];
    }
}
