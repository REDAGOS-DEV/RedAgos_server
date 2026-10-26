<?php

namespace App\Http\Requests;

use App\Support\OperationalDay;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording that a hospital settled a weekly bill outside RedAgos.
 *
 * The hospital's own reference is required: it is the only evidence RedAgos
 * keeps that the money moved. The date is when the hospital settled, which
 * cannot be in the future.
 */
class SettleWeeklyBillRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware, as elsewhere in this application.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'settlement_reference' => ['required', 'string', 'max:100'],
            'settled_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.OperationalDay::todayAsDate()],
            'settlement_note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'settlement_reference.required' => "Enter the hospital's payment reference.",
            'settled_at.before_or_equal' => 'The settlement date cannot be in the future.',
        ];
    }
}
