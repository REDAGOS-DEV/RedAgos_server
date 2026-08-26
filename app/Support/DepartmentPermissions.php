<?php

namespace App\Support;

use App\Enums\Department;
use App\Models\User;

/**
 * The department-to-abilities matrix behind every blood-centre `can:` gate.
 *
 * Permissions are declared here rather than in a database table on purpose:
 * the set of departments is closed and fixed by docs/BLOOD-CENTER.md, so the
 * matrix is versioned with the code that depends on it and is covered by a
 * test rather than by whatever happens to be in a seeder.
 */
final class DepartmentPermissions
{
    /**
     * Abilities every operational department holds regardless of speciality.
     *
     * reference-data serves blood types, components, unit statuses and storage
     * locations, which all four departments consume. It is still an operational
     * endpoint though, so a staff account carrying no department must not reach
     * it — which is why it is an ability rather than an unguarded route.
     */
    private const SHARED = [
        'reference.view',
        'reports.view_own',
    ];

    /**
     * Abilities granted by each department.
     *
     * Read-only grants that reach across a boundary are deliberate and
     * annotated; they are what lets a department see what it needs without
     * being able to write it.
     *
     * @var array<string, array<int, string>>
     */
    private const MATRIX = [
        Department::Collection->value => [
            'donors.view',
            'donors.manage',
            'appointments.view',
            'appointments.verify',
            'drives.view',
            'drives.manage',
            'donations.view',
            'donations.record',

            // Read-only: collection staff check stock levels when advising
            // donors which components are most needed. They never write units.
            'inventory.view',
        ],

        Department::Laboratory->value => [
            'lab.view',
            'lab.record_result',
            'lab.update_status',

            // Read-only: processing acts on donations and hands validated
            // units downstream, but inventory records belong to Inventory.
            'donations.view',
            'inventory.view',
        ],

        Department::Inventory->value => [
            'inventory.view',
            'inventory.create',
            'inventory.update',
            'inventory.discard',
            'requests.view',
            'requests.process',
            'requests.approve',
            'requests.release',

            // Read-only: no unit may be released without confirmed payment,
            // so release needs to read billing status without altering it.
            'billing.view',

            'donations.view',
        ],

        Department::Billing->value => [
            'billing.view',
            'billing.create',
            'billing.record_payment',

            // Read-only: billing is raised against a fulfilled request, so it
            // must read the request it is billing for.
            'requests.view',
        ],
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
     * is_supervisor and department are orthogonal: a supervisor gets the full
     * set whether or not they also work in a department, so the flag is checked
     * first and short-circuits. A user with neither holds nothing, which is the
     * intended fail-closed state for a staff account awaiting assignment.
     *
     * @return array<int, string>
     */
    public static function for(User $user): array
    {
        if ($user->is_supervisor) {
            return self::all();
        }

        $department = $user->department?->value;

        if ($department === null) {
            return [];
        }

        return array_values(array_unique([
            ...self::SHARED,
            ...self::MATRIX[$department],
        ]));
    }

    /**
     * Get every ability the application defines, which is what AppServiceProvider registers as gates.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $abilities = [...self::SHARED, ...self::MANAGEMENT];

        foreach (self::MATRIX as $departmentAbilities) {
            $abilities = [...$abilities, ...$departmentAbilities];
        }

        sort($abilities);

        return array_values(array_unique($abilities));
    }

    /**
     * Get the abilities granted by one department, without the shared baseline.
     *
     * Exposed for the matrix test, which asserts each department against the
     * responsibilities document one department at a time.
     *
     * @return array<int, string>
     */
    public static function forDepartment(Department $department): array
    {
        return array_values(array_unique([
            ...self::SHARED,
            ...self::MATRIX[$department->value],
        ]));
    }
}
