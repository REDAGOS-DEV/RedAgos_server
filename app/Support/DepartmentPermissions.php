<?php

namespace App\Support;

use App\Enums\Department;
use App\Enums\StaffPrivilege;
use App\Enums\StaffRole;
use App\Models\User;

/**
 * The abilities behind every blood-centre `can:` gate.
 *
 * Three things decide what a staff member holds:
 *
 *  1. Their role. A predefined StaffRole carries the exact set below; a
 *     custom, typed role gets its department's full set (every predefined
 *     role of that department combined), less correction approval, which
 *     belongs to the department's named approver.
 *  2. Their privileges. The supervisor ticks Read / Write / Update / Delete
 *     per staff member, and only the abilities whose KIND is ticked survive.
 *     Privileges cap a role; they never widen it.
 *  3. The supervisor level (Center Admin), which holds everything and is not
 *     capped.
 *
 * Declared here rather than in a database table on purpose: the matrix is
 * versioned with the code that depends on it and covered by a test.
 *
 * The matrix decides who may *reach* an endpoint. The clinical hard rules —
 * a reactive result can never be undone, a unit leaves quarantine only on both
 * clearance tokens — are enforced in the services, so that a supervisor, who
 * holds every ability, is bound by them too.
 */
final class DepartmentPermissions
{
    /**
     * Abilities every role holds regardless of speciality.
     *
     * reference-data serves blood types, components, unit statuses and storage
     * locations, which every department consumes. It is still an operational
     * endpoint though, so a staff account carrying no role must not reach it —
     * which is why it is an ability rather than an unguarded route.
     */
    private const SHARED = [
        'reference.view',
        'reports.view_own',
    ];

    /**
     * What a phlebotomist and an apheresis specialist both hold.
     *
     * The collection chair: record the bag, and close a donation that cannot
     * go ahead. Neither may screen, so neither can clear a deferred donor.
     */
    private const CHAIRSIDE = [
        // The chair confirms who is in it before drawing.
        'donors.view_identity',

        'appointments.view',
        'appointments.verify',
        'donations.view',
        'donations.collect',
        'donations.close',

        // Correct their own collection box, on the physician's approval.
        'corrections.request',
    ];

    /**
     * What a billing clerk and a billing supervisor both hold.
     */
    private const BILLING_DESK = [
        'billing.view',
        'billing.create',
        'billing.record_payment',

        // Read-only: billing is raised against a fulfilled request, so it
        // must read the request it is billing for.
        'requests.view',

        // Correct a recorded payment, on the Billing Supervisor's approval.
        'corrections.request',
    ];

    /**
     * Abilities granted by each predefined role.
     *
     * Read-only grants that reach across a boundary are deliberate and
     * annotated; they are what lets a role see what it needs without being
     * able to write it.
     *
     * @var array<string, array<int, string>>
     */
    private const MATRIX = [
        // Donor/Collection. The counter's work is split by who does each part
        // of the visit: the receptionist opens it, the physician screens, the
        // chair collects.
        StaffRole::ScreeningPhysician->value => [
            'donors.view_contact',
            'donors.view',

            // The donor's clinical record: donation history with deferral
            // reasons, screening vitals, and the final laboratory result
            // (read-only — never the individual marker).
            'donors.view_clinical',

            'donors.view_identity',

            // Deliberately separate from donors.view, which also gates the
            // profile. Reading a donor's thirty declared health answers is a
            // different act from looking them up.
            'donors.view_questionnaire',

            'appointments.view',
            'appointments.verify',
            'donations.view',

            // Accept or defer: the override authority. Nobody else at the
            // counter may clear a donor.
            'donations.screen',
            'donations.close',

            // Donor/Collection's correction approver; their own screening
            // corrections go to the Center Admin.
            'corrections.request',
            'corrections.approve',
        ],

        StaffRole::Phlebotomist->value => self::CHAIRSIDE,

        // Identical to a phlebotomist until apheresis procedure metrics land
        // with their own ability.
        StaffRole::ApheresisSpecialist->value => self::CHAIRSIDE,

        StaffRole::MedicalReceptionist->value => [
            'donors.view_contact',
            'donors.view',
            'donors.manage',
            'donors.view_identity',
            'appointments.view',
            'appointments.verify',

            // Donor-facing scheduling includes the mobile drives.
            'drives.view',
            'drives.manage',

            'donations.view',

            // Opens the visit's donation record at check-in.
            'donations.register',
            'donations.close',
        ],

        // Processing. Blind: none of these roles holds donors.view_identity,
        // so the laboratory and intake payloads name no donor to them (see
        // DonorBlinding).
        StaffRole::ComponentTechnologist->value => [
            'lab.view',
            'lab.record_components',

            // Complete or reject. The only donation status write the
            // laboratory side holds.
            'lab.update_status',

            // Processing's correction approver.
            'corrections.request',
            'corrections.approve',

            // Read-only: processing hands units downstream, but inventory
            // records belong to Issuance.
            'inventory.view',
        ],

        // Declares the bags and their volumes but cannot complete: the final
        // call on a donation is the technologist's.
        StaffRole::ProcessingAssistant->value => [
            'lab.view',
            'lab.record_components',
            'corrections.request',
        ],

        // Testing: the TTI panel and the ABO/Rh typing, one department.
        StaffRole::SerologyTechnologist->value => [
            'lab.view',

            // The five-marker panel. A reactive marker rejects the donation
            // automatically — the consequence of a reading only this
            // department may record, not a status anyone sets.
            'lab.record_serology',
            'lab.record_immunohematology',

            'corrections.request',
        ],

        StaffRole::LabSupervisor->value => [
            'lab.view',
            'lab.record_serology',
            'lab.record_immunohematology',

            // The counselling referral list: the only screen that names which
            // marker a donor was reactive for. The supervisor alone, because
            // the technologists who record the markers are blind to who the
            // donor is, and the list exists to put a name to a result.
            'lab.referrals',
            'donors.view_identity',

            // Testing's correction approver.
            'corrections.request',
            'corrections.approve',

            'inventory.view',
        ],

        // Issuance.
        StaffRole::InventoryControlOfficer->value => [
            'inventory.view',
            'inventory.create',
            'inventory.update',
            'inventory.discard',

            // Define the minimum stock per blood type and component. The
            // officer who manages the shelf sets what it must never fall
            // below; everyone with inventory.view can see the result.
            'inventory.thresholds',

            // Release from quarantine. The service demands both clearance
            // tokens, so holding this is never enough on its own.
            'inventory.release_quarantine',

            'requests.view',
            'requests.process',
            'requests.approve',
            'requests.release',

            // Record a walk-in Patient Transfusion request once the hospital
            // blood bank has confirmed it by phone. Issuance's, because it is
            // the counter a watcher reaches and the department that fills it.
            'requests.record',

            // Read-only: no unit may be released without confirmed payment,
            // so release needs to read billing status without altering it.
            'billing.view',

            // Issuance's correction approver. Edits a unit directly (above),
            // so their own dispatch corrections go to the Center Admin.
            'corrections.request',
            'corrections.approve',
        ],

        // Hospital orders and release to transport. No lab.* and no
        // donations.*: dispatch never sees a clinical screen.
        StaffRole::DispatchCoordinator->value => [
            'inventory.view',
            'requests.view',
            'requests.approve',
            'requests.release',

            // Hospital orders are dispatch's, including the ones a watcher
            // walks in with.
            'requests.record',

            'billing.view',

            // Correct a dispatch record, on the Inventory Control Officer's
            // approval.
            'corrections.request',
        ],

        // Audits records against scanned barcodes. Cannot edit them: a
        // discrepancy is filed as a correction for the Inventory Control
        // Officer to decide.
        StaffRole::ItDataClerk->value => [
            'inventory.view',
            'inventory.audit',
            'corrections.request',
        ],

        StaffRole::BillingClerk->value => self::BILLING_DESK,

        // The desk's head: everything a clerk does, and Billing's correction
        // approver. Records payments too, so their own corrections go to the
        // Center Admin.
        StaffRole::BillingSupervisor->value => [
            ...self::BILLING_DESK,
            'corrections.approve',
        ],
    ];

    /**
     * Which privilege each ability falls under.
     *
     * Read views. Write records something new. Update changes a status or
     * decides on something. Delete closes a donation or discards a unit.
     * Every ability a role can hold must appear here, or it would slip past
     * the cap — the matrix test enforces that.
     *
     * @var array<string, string>
     */
    private const KIND = [
        'reference.view' => 'read',
        'reports.view_own' => 'read',
        'donors.view_contact' => 'read',
        'donors.view' => 'read',
        'donors.view_clinical' => 'read',
        'donors.view_identity' => 'read',
        'donors.view_questionnaire' => 'read',
        'appointments.view' => 'read',
        'drives.view' => 'read',
        'donations.view' => 'read',
        'lab.view' => 'read',
        'inventory.view' => 'read',
        'requests.view' => 'read',
        'billing.view' => 'read',

        'donors.manage' => 'write',
        'drives.manage' => 'write',
        'donations.register' => 'write',
        'donations.screen' => 'write',
        'donations.collect' => 'write',
        'lab.record_serology' => 'write',
        'lab.record_immunohematology' => 'write',
        'lab.record_components' => 'write',
        'inventory.create' => 'write',

        // File a correction to a unit's details. Held by the IT clerk alone,
        // and left out of custom roles (see forCustomRole()).
        'inventory.audit' => 'write',
        'requests.process' => 'write',
        'requests.record' => 'write',
        'billing.create' => 'write',
        'billing.record_payment' => 'write',
        'corrections.request' => 'write',

        'appointments.verify' => 'update',
        'lab.update_status' => 'update',
        'lab.referrals' => 'update',
        'inventory.update' => 'update',
        'inventory.thresholds' => 'update',
        'inventory.release_quarantine' => 'update',
        'requests.approve' => 'update',
        'requests.release' => 'update',
        'corrections.approve' => 'update',

        'donations.close' => 'delete',
        'inventory.discard' => 'delete',
    ];

    /**
     * Abilities held by a supervisor, which are every operational ability plus management.
     *
     * Spelled out as a real list rather than short-circuited through
     * Gate::before(), which would also override DonationAppointmentPolicy and
     * make a supervisor the owner of every donor's appointment.
     *
     * @var array<int, string>
     */
    private const MANAGEMENT = [
        'staff.manage',
        'center.configure',
        'reports.view_all',
    ];

    /**
     * Resolve the abilities a user holds.
     *
     * A supervisor gets the full set, uncapped. Anyone else gets their role's
     * set — predefined, or their department's for a custom role — capped by
     * their privileges. A user with neither role holds nothing, which is the
     * intended fail-closed state for a staff account awaiting assignment.
     *
     * @return array<int, string>
     */
    public static function for(User $user): array
    {
        if ($user->is_supervisor) {
            return self::all();
        }

        $abilities = match (true) {
            $user->staff_role !== null => self::forRole($user->staff_role),
            filled($user->custom_role) && $user->department !== null => self::forCustomRole($user->department),
            default => [],
        };

        return self::cap($abilities, self::privilegesOf($user));
    }

    /**
     * The privileges in force for a user: what was ticked, or all four when
     * nothing was ever stored (accounts created before privileges existed).
     *
     * @return array<int, StaffPrivilege>
     */
    public static function privilegesOf(User $user): array
    {
        $stored = $user->staff_privileges;

        if (! is_array($stored)) {
            return StaffPrivilege::cases();
        }

        return array_values(array_filter(
            StaffPrivilege::cases(),
            fn (StaffPrivilege $privilege): bool => in_array($privilege->value, $stored, true)
        ));
    }

    /**
     * Keep only the abilities whose kind is among the given privileges.
     *
     * The shared baseline survives any cap: reference data is what every page
     * draws its lists from, and it reveals no record.
     *
     * @param  array<int, string>  $abilities
     * @param  array<int, StaffPrivilege>  $privileges
     * @return array<int, string>
     */
    public static function cap(array $abilities, array $privileges): array
    {
        $allowed = array_map(fn (StaffPrivilege $privilege): string => $privilege->value, $privileges);

        return array_values(array_filter(
            $abilities,
            fn (string $ability): bool => in_array($ability, self::SHARED, true)
                || in_array(self::KIND[$ability] ?? null, $allowed, true)
        ));
    }

    /**
     * Get every ability the application defines, which is what AppServiceProvider registers as gates.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $abilities = [...self::SHARED, ...self::MANAGEMENT];

        foreach (self::MATRIX as $roleAbilities) {
            $abilities = [...$abilities, ...$roleAbilities];
        }

        sort($abilities);

        return array_values(array_unique($abilities));
    }

    /**
     * Get the abilities one predefined role holds, uncapped, including the shared baseline.
     *
     * @return array<int, string>
     */
    public static function forRole(StaffRole $role): array
    {
        return array_values(array_unique([
            ...self::SHARED,
            ...self::MATRIX[$role->value],
        ]));
    }

    /**
     * Abilities that belong to a named post rather than to a department.
     *
     * Approving is the named approver role's responsibility
     * (Department::correctionApprover()), and auditing a unit is the IT
     * clerk's. A typed role never inherits either.
     *
     * @var array<int, string>
     */
    private const NAMED_POST_ONLY = [
        'corrections.approve',
        'inventory.audit',
        'inventory.thresholds',
    ];

    /**
     * Get the abilities of a custom role in a department, uncapped.
     *
     * Everything the department's predefined roles hold between them, less
     * the abilities that belong to a named post.
     *
     * @return array<int, string>
     */
    public static function forCustomRole(Department $department): array
    {
        return array_values(array_diff(self::forDepartment($department), self::NAMED_POST_ONLY));
    }

    /**
     * Get every ability held by at least one predefined role in a department.
     *
     * @return array<int, string>
     */
    public static function forDepartment(Department $department): array
    {
        $abilities = [];

        foreach (StaffRole::forDepartment($department) as $role) {
            $abilities = [...$abilities, ...self::forRole($role)];
        }

        return array_values(array_unique($abilities));
    }

    /**
     * Get the privilege an ability falls under, or null for a management ability.
     */
    public static function kindOf(string $ability): ?string
    {
        return self::KIND[$ability] ?? null;
    }

    /**
     * Get the predefined roles that hold an ability.
     *
     * @return array<int, StaffRole>
     */
    public static function rolesHolding(string $ability): array
    {
        return array_values(array_filter(
            StaffRole::cases(),
            fn (StaffRole $role): bool => in_array($ability, self::forRole($role), true)
        ));
    }

    /**
     * Get the departments whose custom roles would hold an ability.
     *
     * @return array<int, Department>
     */
    public static function departmentsHolding(string $ability): array
    {
        return array_values(array_filter(
            Department::cases(),
            fn (Department $department): bool => in_array($ability, self::forCustomRole($department), true)
        ));
    }

    /**
     * Get the departments, their roles, and the privileges as the staff form renders them.
     *
     * The labels and descriptions travel with each entry so the form describes
     * a role in the same words this file enforces it.
     *
     * @return array<string, mixed>
     */
    public static function catalogue(): array
    {
        $departments = [];

        foreach (Department::cases() as $department) {
            $departments[] = [
                'department' => $department->value,
                'label' => $department->label(),
                'roles' => array_map(fn (StaffRole $role): array => [
                    'key' => $role->value,
                    'label' => $role->label(),
                    'description' => $role->description(),
                    'abilities' => self::forRole($role),
                ], $department->roles()),
            ];
        }

        return [
            'departments' => $departments,
            'privileges' => array_map(fn (StaffPrivilege $privilege): array => [
                'key' => $privilege->value,
                'label' => $privilege->label(),
                'description' => $privilege->description(),
            ], StaffPrivilege::cases()),
            'titles' => ['RMT', 'RN'],
        ];
    }
}
