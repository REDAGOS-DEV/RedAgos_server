<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveReplenishmentScheduleRequest extends FormRequest
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
     * ISO weekdays, 1 = Monday … 7 = Sunday. A schedule with no days is not a
     * schedule — removing one is its own route.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'days_of_week' => ['required', 'array', 'min:1', 'max:7'],
            'days_of_week.*' => ['required', 'integer', 'between:1,7', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'days_of_week.required' => 'Choose at least one request day.',
            'days_of_week.min' => 'Choose at least one request day.',
            'days_of_week.*.between' => 'A request day is a day of the week.',
            'days_of_week.*.distinct' => 'Each day can only be chosen once.',
        ];
    }
}
