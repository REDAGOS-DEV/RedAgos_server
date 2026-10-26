<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowFinancialReportRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware, as elsewhere in this application.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The centre comes from the authenticated user, never from input. Dates
     * are operational (Manila) days, at most a year apart.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', function (string $attribute, mixed $value, \Closure $fail): void {
                $from = $this->input('from');

                if (is_string($from) && strtotime((string) $value) - strtotime($from) > 366 * 86400) {
                    $fail('Choose a period of a year or less.');
                }
            }],
        ];
    }
}
