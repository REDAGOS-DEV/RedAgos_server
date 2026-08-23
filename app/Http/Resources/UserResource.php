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
            'blood_type' => $this->whenLoaded(
                'donorProfile',
                fn () => $this->donorProfile?->bloodType?->code
            ),
        ];
    }
}
