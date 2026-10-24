<?php

namespace App\Support;

use App\Enums\Department;
use App\Enums\ManagementLevel;
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
 *  2. Their privileges. The Admin ticks Read / Write / Update / Delete per
 *     staff member, and only the abilities whose KIND is ticked survive.
 *     Privileges cap a role; they never widen it.
 *  3. Their management level (ManagementLevel), which adds a fixed set on top
 *     of whatever the role gives and is not capped. Both see the overview and
 *     the reports (OVERVIEW). The Supervisor also watches every department's
 *     monitoring pages (MONITORING); the Blood Center Admin instead keeps the
 *     staff accounts and the centre's settings (ADMINISTRATION). Neither does
 *     department work: until 2026-10-15 one supervisor flag held every
 *     ability, and the project owner split it so that it no longer does.
 *
 * Declared here rather than in a database table on purpose: the matrix is
 * versioned with the code that depends on it and covered by a test.
 *
 * The matrix decides who may *reach* an endpoint. The clinical hard rules —
 * a reactive result can never be undone, a unit leaves quarantine only on both
 * clearance tokens — are enforced in the services, so that they bind everyone
 * who reaches it.
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

        // Type in a remote drive's paper records once they come back (project
        // owner, 2026-10-17). Every Collection role and the head may: whoever
        // ran the drive brings the sheets home.
        'donations.record_paper',

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

        // Meet a statement from the government subsidy. Issuance holds it
        // too (below), for a centre with nobody at the billing desk.
        'billing.subsidise',

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

            // The Donation counter's printed form and its onsite form for
            // walk-ins; see the receptionist's entry below.
            'donors.print_questionnaire',
            'donors.record_questionnaire',

            'appointments.view',
            'appointments.verify',
            'donations.view',

            // Accept or defer: the override authority. Nobody else at the
            // counter may clear a donor.
            'donations.screen',
            'donations.close',

            // Defer a donor outside a visit, and lift that deferral, on the
            // Deferrals page (project owner, 2026-10-18). The same authority
            // as deferring at screening.
            'donors.defer',

            // A drive's paper records; see CHAIRSIDE.
            'donations.record_paper',

            // Donor/Collection's correction approver; their own screening
            // corrections apply when filed (CorrectionService::request()).
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

            // Book an appointment for a donor who phones in, and cancel one
            // (project owner, 2026-10-17). Removing a booking entered by
            // mistake is its own, delete-kind ability, so the Delete privilege
            // still caps it.
            'appointments.manage',
            'appointments.delete',

            // Donor-facing scheduling includes the mobile drives.
            'drives.view',
            'drives.manage',

            // The centre's booking days, hours and places (project owner,
            // 2026-10-24); the Admin holds it too (ADMINISTRATION).
            'booking.configure',

            'donations.view',

            // Opens the visit's donation record at check-in.
            'donations.register',
            'donations.close',

            // A drive's paper records; see CHAIRSIDE.
            'donations.record_paper',

            // The desk that scans a booked donor in prints their completed
            // questionnaire to travel with them to the physician (project
            // owner, 2026-10-16), and hands a walk-in the counter's device to
            // answer it. The printed form carries the answers only: the review
            // markers and the server's assessment stay behind
            // donors.view_questionnaire, which the receptionist still lacks.
            'donors.print_questionnaire',
            'donors.record_questionnaire',
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

            // Add a component to the catalogue and set this centre's shelf
            // life for it, which decides every unit's expiry date. Processing
            // makes the components, so Processing's head defines them; the
            // price is Billing's (billing.prices).
            'components.configure',

            // Processing's correction approver.
            'corrections.request',
            'corrections.approve',

            // No inventory.view (project owner, 2026-10-19): Processing hands
            // units over and never works on stock, so Blood Inventory and
            // Stock Thresholds are not its pages, and it gets no low-stock
            // alerts. The Processed list says how many bags Issuance has
            // booked in.
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

            // The second person on a reactive result (project owner,
            // 2026-10-18): a technologist's reactive panel sets nothing off
            // until the supervisor checks it against the bench record and
            // verifies it, or returns it for re-entry.
            'lab.verify_serology',

            // Testing's correction approver.
            'corrections.request',
            'corrections.approve',

            // No inventory.view (project owner, 2026-10-18): Testing works on
            // drawn donations, never on stock, so Blood Inventory and Stock
            // Thresholds are not its pages, and it gets no low-stock alerts.
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

            // Read the Daily Blood Stock Inventory. Issuance prepares it; its
            // own ability rather than inventory.create so a Supervisor can
            // read the sheet without being able to book stock in.
            'inventory.stock_report',

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

            // Meet the statement from the government subsidy at the point of
            // issue (project owner, 2026-10-19): a subsidised centre may have
            // no billing staff, and the units still need clearing to go. It
            // takes no money, and the request still goes on the hospital's
            // Statement of Account.
            'billing.subsidise',

            // Issuance's correction approver. Edits a unit directly (above),
            // and their own dispatch corrections apply when filed.
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

            // Releases too, so may clear a statement by the subsidy, as the
            // Inventory Control Officer may (project owner, 2026-10-19).
            'billing.subsidise',

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
        // approver. Records payments too; their own corrections apply when
        // filed.
        StaffRole::BillingSupervisor->value => [
            ...self::BILLING_DESK,
            'corrections.approve',

            // Price each component. A commercial decision, and a non-zero
            // price switches on the payment-before-release gate, so it is the
            // Billing department's alone and only its head's.
            'billing.prices',
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
        'donors.print_questionnaire' => 'read',
        'appointments.view' => 'read',
        'drives.view' => 'read',
        'donations.view' => 'read',
        'lab.view' => 'read',
        'inventory.view' => 'read',
        'inventory.stock_report' => 'read',
        'requests.view' => 'read',
        'billing.view' => 'read',

        'donors.manage' => 'write',
        'donors.record_questionnaire' => 'write',
        'donors.defer' => 'write',
        'drives.manage' => 'write',
        'booking.configure' => 'update',
        'appointments.manage' => 'write',
        'donations.register' => 'write',
        'donations.screen' => 'write',
        'donations.collect' => 'write',
        'donations.record_paper' => 'write',
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
        'lab.verify_serology' => 'update',
        'inventory.update' => 'update',
        'inventory.thresholds' => 'update',

        // Settings a named post keeps, like the stock minimums above: setting
        // a value rather than recording a new entry.
        'components.configure' => 'update',
        'billing.prices' => 'update',
        'requests.approve' => 'update',
        'requests.release' => 'update',

        // Decides that the government meets a statement: a settlement, not a
        // new record.
        'billing.subsidise' => 'update',
        'corrections.approve' => 'update',

        'donations.close' => 'delete',
        'inventory.discard' => 'delete',
        'appointments.delete' => 'delete',
    ];

    /**
     * What both levels hold: the overview (the dashboard) and the reports.
     *
     * @var array<int, string>
     */
    private const OVERVIEW = [
        'reference.view',
        'reports.view_own',
        'reports.view_all',
    ];

    /**
     * What the Supervisor level holds: the overview, and every department's
     * view-only monitoring.
     *
     * Read abilities only, chosen by the pages they open: Blood Inventory and
     * Stock Thresholds, the Daily Stock Report, Incoming Requests and the
     * billing Statements. Each of those pages hides what its viewer cannot
     * do. The clinical reads (donor identity, questionnaires, screening and
     * laboratory detail) are left out: a supervisor monitors the centre's
     * numbers, not a donor's record.
     *
     * @var array<int, string>
     */
    private const MONITORING = [
        ...self::OVERVIEW,
        'inventory.view',
        'inventory.stock_report',
        'requests.view',
        'billing.view',
    ];

    /**
     * What the Blood Center Admin holds: the overview, the staff accounts and
     * their roles, the centre's own settings (its logo), and the Audit Log of
     * what the centre's staff did (project owner, 2026-10-21). Nothing else.
     *
     * The department monitoring pages are the Supervisor's, and corrections
     * are the departments' own: a head's correction to their own record
     * applies when filed (project owner, 2026-10-15), so nothing escalates to
     * the Admin. Shelf life is Processing's (components.configure) and price
     * is Billing's (billing.prices).
     *
     * @var array<int, string>
     */
    private const ADMINISTRATION = [
        ...self::OVERVIEW,
        'staff.manage',
        'center.configure',
        'booking.configure',
        'audit.view',
    ];

    /**
     * Abilities only a management level grants. No staff role holds them,
     * and the matrix test keeps it that way.
     *
     * Spelled out as real lists rather than short-circuited through
     * Gate::before(), which would also override DonationAppointmentPolicy and
     * make an Admin the owner of every donor's appointment.
     *
     * @var array<int, string>
     */
    private const MANAGEMENT = [
        'staff.manage',
        'center.configure',
        'reports.view_all',
        'audit.view',
    ];

    /**
     * Resolve the abilities a user holds.
     *
     * Their roles' sets combined — each predefined role's, plus their
     * department's for a custom role — capped by their one set of privileges,
     * plus whatever their management level
     * adds, uncapped. A user with neither a role nor a level holds nothing,
     * which is the intended fail-closed state for a staff account awaiting
     * assignment.
     *
     * @return array<int, string>
     */
    public static function for(User $user): array
    {
        // Every role the account holds, combined: a person covering two posts
        // in one department does both jobs (project owner, 2026-10-17). A
        // custom role adds its department's set, less the named posts' own.
        $abilities = [];

        foreach ($user->staffRoles() as $role) {
            $abilities = [...$abilities, ...self::forRole($role)];
        }

        if (filled($user->custom_role) && $user->department !== null) {
            $abilities = [...$abilities, ...self::forCustomRole($user->department)];
        }

        return array_values(array_unique([
            ...self::cap($abilities, self::privilegesOf($user)),
            ...self::forLevel($user->management_level),
        ]));
    }

    /**
     * Get the abilities a management level adds, or none without one.
     *
     * @return array<int, string>
     */
    public static function forLevel(?ManagementLevel $level): array
    {
        return match ($level) {
            ManagementLevel::Admin => self::ADMINISTRATION,
            ManagementLevel::Supervisor => self::MONITORING,
            null => [],
        };
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
        $abilities = [...self::SHARED, ...self::MANAGEMENT, ...self::MONITORING, ...self::ADMINISTRATION];

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
     * (Department::correctionApprover()), as is verifying a reactive serology
     * result, and auditing a unit is the IT clerk's. The stock minimums, the component catalogue with its shelf
     * lives, and the prices are each kept by their department's head. A typed
     * role never inherits any of them.
     *
     * @var array<int, string>
     */
    private const NAMED_POST_ONLY = [
        'corrections.approve',
        'lab.verify_serology',
        'inventory.audit',
        'inventory.thresholds',
        'components.configure',
        'billing.prices',
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
     * Get the departments, their roles, the management levels and the privileges as the staff form renders them.
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
            'levels' => array_map(fn (ManagementLevel $level): array => [
                'key' => $level->value,
                'label' => $level->label(),
                'description' => $level->description(),
                'abilities' => self::forLevel($level),
            ], ManagementLevel::cases()),
            'privileges' => array_map(fn (StaffPrivilege $privilege): array => [
                'key' => $privilege->value,
                'label' => $privilege->label(),
                'description' => $privilege->description(),
            ], StaffPrivilege::cases()),
            // Suggested credentials, printed after a staff member's name (users.credentials).
            'titles' => ['RMT', 'RN', 'MD'],
        ];
    }
}
