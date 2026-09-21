<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplySubsidyRequest extends FormRequest
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
     * The reason is optional because the subsidy is the blood centre's standing
     * arrangement rather than an exception that needs justifying each time. It
     * is recorded when given, since the one case that does need explaining is a
     * statement subsidised after money was already taken.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
