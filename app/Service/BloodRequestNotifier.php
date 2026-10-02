<?php

namespace App\Service;

use App\Enums\Department;
use App\Enums\StaffRole;
use App\Models\BloodRequest;
use App\Models\User;
use App\Notifications\BloodRequestDecided;
use App\Notifications\BloodRequestSubmitted;
use App\Notifications\WalkInRequestRecorded;
use App\Support\DepartmentPermissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears about a blood request, and when.
 *
 * Every send happens after the transaction commits, never inside it: a
 * notification failure must not roll back a request, a hold or a release that
 * has already happened.
 */
class BloodRequestNotifier
{
    /**
     * Notify the staff who will have to act on a newly submitted request.
     *
     * Addressed to whoever can act on it — the roles that decide on or
     * allocate against a request — rather than to a department, so an IT clerk
     * sitting in Issuance is not paged for a request they cannot touch.
     * Supervisors are included because they hold every ability and may be the
     * only account staffing a small centre out of hours.
     */
    public function targetFacility(BloodRequest $request): void
    {
        $roles = array_values(array_unique(array_map(
            fn (StaffRole $role): string => $role->value,
            [
                ...DepartmentPermissions::rolesHolding('requests.approve'),
                ...DepartmentPermissions::rolesHolding('requests.process'),
            ]
        )));

        // Custom roles in a department whose staff can act on requests.
        $departments = array_values(array_unique(array_map(
            fn (Department $department): string => $department->value,
            [
                ...DepartmentPermissions::departmentsHolding('requests.approve'),
                ...DepartmentPermissions::departmentsHolding('requests.process'),
            ]
        )));

        $recipients = User::query()
            ->where('facility_id', $request->target_facility_id)
            ->where(function ($query) use ($roles, $departments): void {
                $query->whereIn('staff_role', $roles)
                    ->orWhere(fn ($custom) => $custom->whereNotNull('custom_role')->whereIn('department', $departments))
                    ->orWhere('is_supervisor', true);
            })
            ->get();

        Notification::send($recipients, new BloodRequestSubmitted($request));
    }

    /**
     * Tell the requesting hospital what became of its request.
     *
     * The hospital user who submitted it hears first. A walk-in has no such
     * user — the hospital confirmed it by phone — so the hospital's own
     * accounts are told instead, because the request is still theirs.
     */
    public function requester(BloodRequest $request, string $outcome): void
    {
        $request->loadMissing('requester');

        Notification::send(
            $request->requester ? collect([$request->requester]) : $this->hospitalAccounts($request),
            new BloodRequestDecided($request, $outcome)
        );
    }

    /**
     * Tell the hospital that a blood centre has recorded a request on its behalf.
     *
     * The hospital confirmed it on the phone; this is the written trace of that
     * call reaching the system, so the hospital can see and track the request
     * it vouched for.
     */
    public function walkInRecorded(BloodRequest $request): void
    {
        Notification::send($this->hospitalAccounts($request), new WalkInRequestRecorded($request));
    }

    /**
     * Every account of the requesting hospital blood bank.
     *
     * A hospital blood bank has no departments — every account does the same
     * job — so there is no narrower audience to pick.
     *
     * @return Collection<int, User>
     */
    private function hospitalAccounts(BloodRequest $request): Collection
    {
        return User::query()->where('facility_id', $request->facility_id)->get();
    }
}
