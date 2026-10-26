<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RemoveSubsidyRequest extends FormRequest
{
    /** Authorization is handled by the route middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Removing a subsidy reopens a statement, so the audit trail needs the
     * operator's explanation rather than treating it as an ordinary toggle.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
