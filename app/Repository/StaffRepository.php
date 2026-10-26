<?php

namespace App\Repository;

use App\Enums\AccountStatus;
use App\Enums\ManagementLevel;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class StaffRepository
{
    /**
     * Resolve one staff account, scoped to the facility the caller acts for.
     *
     * This is the only way a staff row is looked up. The facility id always
     * comes from the authenticated user, never from request input, so a uuid
     * belonging to another centre simply does not resolve — the caller gets a
     * 404 rather than a 403, because a 403 would confirm the account exists.
     */
    public function findForFacility(string $uuid, int $facilityId, bool $withTrashed = false): ?User
    {
        return User::query()
            ->when($withTrashed, fn (Builder $query): Builder => $query->withTrashed())
            ->where('facility_id', $facilityId)
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * Page one facility's roster.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateForFacility(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->when(
                $filters['include_deleted'] ?? false,
                fn (Builder $query): Builder => $query->withTrashed()
            )
            ->where('facility_id', $facilityId)
            ->when(
                isset($filters['department']),
                fn (Builder $query): Builder => $query->where('department', $filters['department'])
            )
            ->when(
                isset($filters['staff_role']),
                // As the main role or any other they hold.
                fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q
                    ->where('staff_role', $filters['staff_role'])
                    ->orWhereJsonContains('additional_staff_roles', $filters['staff_role']))
            )
            ->when(
                isset($filters['account_status']),
                fn (Builder $query): Builder => $query->where('account_status', $filters['account_status'])
            )
            ->when(
                isset($filters['management_level']),
                fn (Builder $query): Builder => $query->where('management_level', $filters['management_level'])
            )
            ->when(
                isset($filters['search']),
                fn (Builder $query): Builder => $query->where(
                    function (Builder $inner) use ($filters): void {
                        $term = '%'.$filters['search'].'%';

                        $inner->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('email', 'like', $term)
                            ->orWhere('employee_id', 'like', $term);
                    }
                )
            )
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * Count the Blood Center Admins at a facility who can actually manage its staff.
     *
     * Only the Admin level counts: a view-only Supervisor cannot manage
     * anyone. Soft-deleted and non-authenticating accounts do not count
     * either: an account that cannot sign in cannot manage anyone, so leaving
     * one behind would be the same lockout the Admin floor exists to prevent.
     * Email verification is deliberately not part of this — it is a gate the
     * holder can clear themselves, unlike suspension or deletion.
     */
    public function countActiveAdmins(int $facilityId): int
    {
        return User::query()
            ->where('facility_id', $facilityId)
            ->where('management_level', ManagementLevel::Admin->value)
            ->whereIn('account_status', $this->authenticatingStatuses())
            ->count();
    }

    /**
     * The account statuses that still permit signing in.
     *
     * Derived from the enum rather than hard-coded so a new status cannot be
     * added without the Admin floor noticing.
     *
     * @return array<int, string>
     */
    private function authenticatingStatuses(): array
    {
        return array_values(array_map(
            fn (AccountStatus $status): string => $status->value,
            array_filter(
                AccountStatus::cases(),
                fn (AccountStatus $status): bool => $status->canAuthenticate()
            )
        ));
    }
}
