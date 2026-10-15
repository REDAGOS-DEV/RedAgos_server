<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Super Admin taking a facility out of service, with the reason on record.
 */
class DeactivateFacilityRequest extends FormRequest
{
    /**
     * Authorisation is the route's job: this endpoint sits behind role:admin and can:.
     */
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
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the facility is being deactivated.',
        ];
    }
}
