<?php

namespace App\Http\Resources;

use App\Enums\CorrectionSubject;
use App\Support\AdminPrivileges;
use App\Support\DepartmentPermissions;
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
            'staff_role' => $this->staff_role?->value,
            'staff_role_label' => $this->staff_role?->label(),
            'custom_role' => $this->custom_role,
            'role_label' => $this->resource->roleLabel(),
            'staff_privileges' => array_map(
                fn ($privilege): string => $privilege->value,
                DepartmentPermissions::privilegesOf($this->resource)
            ),
            'is_supervisor' => (bool) $this->is_supervisor,
            'is_super_admin' => (bool) $this->is_super_admin,
            // The raw grant, separate from `permissions` below. An unrestricted
            // admin holds every privilege without any of them being stored, so
            // the account form needs the stored list to show what was actually
            // ticked rather than what the flag implies.
            'admin_privileges' => AdminPrivileges::for($this->resource),
            // The preset name the stored list matches, or null for "Custom".
            // Derived, never stored — a stored name could disagree with the
            // list the gate reads, and then the screen would be lying.
            'admin_role' => $this->is_super_admin
                ? 'super_admin'
                : AdminPrivileges::presetFor($this->admin_privileges ?? []),
            // Mirrored to the client so it can render the right navigation.
            // This is presentation only — every ability is re-checked by the
            // `can:` middleware on the route that uses it.
            'permissions' => $this->abilities(),
            // The corrections this account may file, which `permissions` alone
            // cannot say: some are open only to a named post. Presentation only —
            // CorrectionService::request() asks the same question on every filing.
            'correction_subjects' => array_values(array_map(
                fn (CorrectionSubject $subject): string => $subject->value,
                array_filter(
                    CorrectionSubject::cases(),
                    fn (CorrectionSubject $subject): bool => $subject->mayBeFiledBy($this->resource)
                )
            )),
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
