<?php

namespace App\Http\Requests;

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
        return [
            'appointment_reminders' => ['required', 'boolean'],
            'eligibility_renewal' => ['required', 'boolean'],
            'nearby_drives' => ['required', 'boolean'],
            'email_updates' => ['required', 'boolean'],
            'donation_updates' => ['sometimes', 'boolean'],
            'blood_drive_announcements' => ['sometimes', 'boolean'],
        ];
    }
}
