<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MatchDrivePreRegistrationRequest extends FormRequest
{
    /**
     * The route's can:drives.manage ability is the gate.
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
            'donor_uuid' => ['required', 'uuid'],
        ];
    }
}
