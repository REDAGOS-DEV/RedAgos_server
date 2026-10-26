<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowAccountStatementRequest extends FormRequest
{
    /**
     * Authorization is handled by the route middleware, as elsewhere in this application.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The issuing centre is deliberately absent: it comes from the
     * authenticated user, never from input. Dates are operational (Manila) days.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'hospital_id' => ['required', 'integer', 'exists:facilities,id'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
