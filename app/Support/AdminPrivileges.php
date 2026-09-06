<?php

namespace App\Support;

use App\Models\User;

/**
 * The privilege catalogue behind every platform-admin `can:` gate.
 *
 * This is the admin counterpart to DepartmentPermissions, and it is declared
 * the same way and for the same reason: the set of admin capabilities is closed
 * and follows the shape of the admin portal itself, so the catalogue is
 * versioned with the code that depends on it and covered by a test rather than
 * by whatever happens to be in a seeder. Only the *assignment* lives in the
 * database — `users.admin_privileges`.
 *
 * Every key is prefixed `admin.` deliberately. AppServiceProvider registers one
 * gate per ability name across both catalogues, so an unprefixed `reports.view`
 * here would silently collide with the blood-centre ability of the same name
 * and hand a scoped admin a department permission nobody granted.
 *
 * Before this existed, `role:admin` was the only guard on every /admin route,
 * which made every admin account all-powerful: an account created to work the
 * donor ID queue could also approve facilities and create further admins.
 */
final class AdminPrivileges
{
    /**
     * Every privilege a platform admin account can hold.
     *
     * The label and description are served to the client so the account form
     * and the audit view describe a privilege the same way the gate enforces
     * it, rather than keeping a second copy of the wording in the SPA.
     *
     * @var array<string, array<string, string>>
     */
    private const CATALOGUE = [
        'admin.donor_identity.verify' => [
            'label' => 'Donor ID Validation',
            'description' => 'Review, approve and reject donor valid IDs.',
        ],
        'admin.facility.approve' => [
            'label' => 'Facility Approvals',
            'description' => 'Decide on blood center and blood bank applications.',
        ],
        'admin.facility.manage' => [
            'label' => 'Facility Management',
            'description' => 'Create facility accounts and edit their details.',
        ],
        'admin.donor.view' => [
            'label' => 'Donor Records',
            'description' => 'Read donor profiles and donation history.',
        ],
        'admin.reports.export' => [
            'label' => 'Reports & Export',
            'description' => 'Generate network reports and export CSVs.',
        ],
        'admin.audit.view' => [
            'label' => 'Audit Log',
            'description' => 'Read the platform audit trail.',
        ],
        'admin.accounts.manage' => [
            'label' => 'Admin Accounts',
            'description' => 'Create admin accounts and assign privileges.',
        ],
    ];

    /**
     * Named presets over the catalogue above.
     *
     * These are a convenience for the account form, not a second permission
     * system: nothing is stored as a preset name. Picking one writes its
     * privilege list onto the account, and changing any single privilege
     * afterwards simply produces a list that matches no preset. That is why
     * `roleFor()` derives the name back from the stored list instead of
     * trusting a column — a stored name and a stored list can disagree, and
     * the one the gate reads must be the one the UI shows.
     *
     * `super_admin` is intentionally absent. Unrestricted access is the
     * `is_super_admin` flag, not a privilege list that happens to be complete,
     * so that a privilege added here later widens existing super admins rather
     * than silently leaving them behind.
     *
     * @var array<string, array<int, string>>
     */
    private const PRESETS = [
        'verification_officer' => [
            'admin.donor_identity.verify',
            'admin.donor.view',
        ],
        'network_admin' => [
            'admin.facility.approve',
            'admin.facility.manage',
            'admin.reports.export',
        ],
        'auditor' => [
            'admin.donor.view',
            'admin.reports.export',
            'admin.audit.view',
        ],
    ];

    /**
     * Resolve the admin privileges a user holds.
     *
     * Deliberately does not consult the roles relation. abilities() is called
     * on every serialised user, and reaching for `roles` here would either
     * trigger a lazy load on a model loaded without it or blow up outright
     * under preventLazyLoading. It does not need to: a donor or a blood-centre
     * staff account has `is_super_admin` false and a null privilege list, so it
     * falls through to an empty set on the data alone.
     *
     * Unknown keys are filtered rather than returned. A privilege removed from
     * the catalogue would otherwise linger in old rows and register as a gate
     * that no longer exists.
     *
     * @return array<int, string>
     */
    public static function for(User $user): array
    {
        if ($user->is_super_admin) {
            return self::all();
        }

        $granted = $user->admin_privileges ?? [];

        if (! is_array($granted) || $granted === []) {
            return [];
        }

        return array_values(array_intersect(self::all(), $granted));
    }

    /**
     * Get every privilege the catalogue defines.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /**
     * Get the catalogue as a list the client can render directly.
     *
     * @return array<int, array<string, string>>
     */
    public static function catalogue(): array
    {
        $catalogue = [];

        foreach (self::CATALOGUE as $key => $meta) {
            $catalogue[] = ['key' => $key, ...$meta];
        }

        return $catalogue;
    }

    /**
     * Get the presets as a list the client can render directly.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function presets(): array
    {
        $presets = [];

        foreach (self::PRESETS as $name => $privileges) {
            $presets[] = ['name' => $name, 'privileges' => $privileges];
        }

        return $presets;
    }

    /**
     * Name the preset that exactly matches a privilege list, if one does.
     *
     * Returns null for a list matching no preset, which the client renders as
     * "Custom". Compared as sorted sets so ordering never decides the answer.
     *
     * @param  array<int, string>  $privileges
     */
    public static function presetFor(array $privileges): ?string
    {
        $signature = self::signature($privileges);

        foreach (self::PRESETS as $name => $preset) {
            if (self::signature($preset) === $signature) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Get the privileges a preset grants, or an empty list for an unknown name.
     *
     * @return array<int, string>
     */
    public static function preset(string $name): array
    {
        return self::PRESETS[$name] ?? [];
    }

    /**
     * @param  array<int, string>  $privileges
     */
    private static function signature(array $privileges): string
    {
        $unique = array_values(array_unique($privileges));
        sort($unique);

        return implode('|', $unique);
    }
}
