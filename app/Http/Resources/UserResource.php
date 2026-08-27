<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Fields are whitelisted rather than inherited from the model so that
     * credentials, internal keys and health-adjacent profile data are never
     * exposed by accident.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim($this->first_name.' '.$this->last_name),
            'email' => $this->email,
            'phone' => $this->phone,
            'username' => $this->username,
            'account_status' => $this->account_status?->value,
            'email_verified' => $this->hasVerifiedEmail(),
            'activated_at' => $this->activated_at?->toISOString(),
            'roles' => $this->whenLoaded(
                'roles',
                fn () => $this->roles->pluck('name')->values()->all(),
                []
            ),
            'department' => $this->department?->value,
            'department_label' => $this->department?->label(),
            'is_supervisor' => (bool) $this->is_supervisor,
            // Mirrored to the client so it can render the right navigation.
            // This is presentation only — every ability is re-checked by the
            // `can:` middleware on the route that uses it.
            'permissions' => $this->abilities(),
            'blood_type' => $this->whenLoaded(
                'donorProfile',
                fn () => $this->donorProfile?->bloodType?->code
            ),
            'facility' => $this->whenLoaded(
                'facility',
                fn () => $this->facility ? [
                    'id' => $this->facility->id,
                    'facility_name' => $this->facility->name,
                    'address' => $this->facility->address,
                    'status' => $this->facility->status?->value,
                ] : null
            ),
        ];
    }
}
