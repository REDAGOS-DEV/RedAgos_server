<?php

namespace App\Http\Requests;

use App\Support\DonorNotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDonorNotificationPreferencesRequest extends FormRequest
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
        // The switches in DonorNotificationPreferences::DEFAULTS. Each is
        // optional, so a screen can save one row; the rest keep their value.
        return array_map(
            fn (): array => ['sometimes', 'boolean'],
            DonorNotificationPreferences::DEFAULTS,
        );
    }
}
