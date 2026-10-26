<?php

namespace App\Service;

use App\Enums\AccountStatus;
use App\Enums\Department;
use App\Enums\RoleName;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use App\Support\AuditActions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

/**
 * The Blood Center Admin's dashboard: the centre's people and setup, not its
 * blood. The Admin keeps the staff accounts, the centre's settings and the
 * audit log (DepartmentPermissions::ADMINISTRATION), so this answers "is my
 * team in place and my centre set up?".
 *
 * Role coverage is the part only the Admin can act on: a department without
 * its head cannot approve its corrections, and a centre without a Laboratory
 * Supervisor cannot verify a reactive result.
 */
class AdminOverviewService
{
    /**
     * The posts every centre needs filled, and what stops without them.
     *
     * @var array<int, array{0: Department, 1: StaffRole, 2: string}>
     */
    private const COVERAGE = [
        [Department::Collection, StaffRole::ScreeningPhysician, 'Screens donors and approves Collection\'s corrections.'],
        [Department::Collection, StaffRole::MedicalReceptionist, 'Runs the appointments and the mobile drives.'],
        [Department::Testing, StaffRole::LabSupervisor, 'Verifies reactive results. Without one, they wait.'],
        [Department::Processing, StaffRole::ComponentTechnologist, 'Separates components and approves Processing\'s corrections.'],
        [Department::Issuance, StaffRole::InventoryControlOfficer, 'Decides on hospital requests and approves Issuance\'s corrections.'],
        [Department::Billing, StaffRole::BillingSupervisor, 'Heads Billing and approves its corrections.'],
    ];

    /** How many recent staff and settings changes the dashboard lists. */
    private const ACTIVITY = 6;

    /**
     * @return array<string, mixed>
     */
    public function summary(User $admin): array
    {
        $admin->loadMissing('facility');
        $facility = $admin->facility ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');

        $staff = $this->staffOf($facility);
        $current = $staff->filter(fn (User $user): bool => $user->account_status !== AccountStatus::Deactivated);
        $canWork = $staff->filter(fn (User $user): bool => $user->account_status?->canAuthenticate() ?? false);

        $coverage = array_map(function (array $post) use ($canWork): array {
            [$department, $role, $why] = $post;
            $count = $canWork->filter(fn (User $user): bool => $user->hasStaffRole($role))->count();

            return [
                'department' => $department->value,
                'department_label' => $department->label(),
                'role' => $role->value,
                'role_label' => $role->label(),
                'why' => $why,
                'count' => $count,
                'filled' => $count > 0,
            ];
        }, self::COVERAGE);

        // No role, no custom role and no level: an account that can do nothing yet.
        $unassigned = $current->filter(fn (User $user): bool => $user->staff_role === null
            && blank($user->custom_role)
            && $user->management_level === null);
        $pending = $staff->filter(fn (User $user): bool => $user->account_status === AccountStatus::PendingVerification);
        $setup = [
            'logo' => filled($facility->logo_path),
            'seal' => filled($facility->seal_path),
        ];

        return [
            'needs' => [
                'coverage_gaps' => count(array_filter($coverage, fn (array $post): bool => ! $post['filled'])),
                'unassigned' => $unassigned->count(),
                'pending_activation' => $pending->count(),
                'setup_missing' => count(array_filter($setup, fn (bool $done): bool => ! $done)),
            ],
            'coverage' => $coverage,
            'team' => [
                'total' => $current->count(),
                'active' => $staff->filter(fn (User $user): bool => $user->account_status === AccountStatus::Active)->count(),
                'pending' => $pending->count(),
                'suspended' => $staff->filter(fn (User $user): bool => $user->account_status === AccountStatus::Suspended)->count(),
                'deactivated' => $staff->count() - $current->count(),
                'by_department' => array_map(fn (Department $department): array => [
                    'department' => $department->value,
                    'label' => $department->label(),
                    'count' => $current->filter(fn (User $user): bool => $user->department === $department)->count(),
                ], Department::cases()),
            ],
            'unassigned_staff' => $this->people($unassigned),
            'pending_staff' => $this->people($pending),
            'setup' => $setup,
            'activity' => $this->activity($facility),
            'changes_this_week' => $this->changesSince($facility, now()->subDays(7)),
        ];
    }

    /**
     * The centre's staff accounts, the Admin's own included.
     *
     * @return Collection<int, User>
     */
    private function staffOf(Facility $facility): Collection
    {
        return User::query()
            ->where('facility_id', $facility->id)
            ->whereHas('roles', fn (Builder $q) => $q->where('name', RoleName::BloodCenter->value))
            ->get();
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, array{uuid: string, name: string}>
     */
    private function people(Collection $users): array
    {
        return $users->take(5)->map(fn (User $user): array => [
            'uuid' => (string) $user->uuid,
            'name' => trim($user->full_name ?? '') ?: trim($user->first_name.' '.$user->last_name),
        ])->values()->all();
    }

    /**
     * The latest changes to the centre's staff and settings, the audit log's
     * part the Admin watches.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activity(Facility $facility): array
    {
        $actions = [...AuditActions::inModule('staff'), ...AuditActions::inModule('settings')];

        return AuditLog::query()
            ->with('actor')
            ->where('facility_id', $facility->id)
            ->whereIn('action', $actions)
            ->latest('id')
            ->limit(self::ACTIVITY)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'label' => AuditActions::describe($log->action)['label'],
                'by' => $log->actor?->signatureName() ?? 'System',
                'at' => $log->created_at?->toISOString(),
            ])
            ->all();
    }

    /** How many staff and settings changes since a moment, for the summary band. */
    private function changesSince(Facility $facility, \DateTimeInterface $since): int
    {
        return AuditLog::query()
            ->where('facility_id', $facility->id)
            ->whereIn('action', [...AuditActions::inModule('staff'), ...AuditActions::inModule('settings')])
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
