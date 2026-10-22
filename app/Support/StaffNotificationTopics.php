<?php

namespace App\Support;

use App\Enums\Department;
use App\Enums\ManagementLevel;
use App\Enums\StaffRole;
use App\Models\CorrectionRequest;
use App\Models\User;

/**
 * Which notices a blood-centre staff member gets, and which they may turn off.
 *
 * One place for both the senders (BloodRequestNotifier, LaboratoryService,
 * StockAlertNotifier) and the staff Settings screen, so the list a person is
 * shown is the list they are sent. Each notice goes to whoever can act on it,
 * by role or ability, never by department alone.
 *
 * The tasks are always on, because a missed one holds up a patient or a
 * donor: hospital requests, reactive serology to verify, corrections to
 * decide and counselling referrals nobody has acted on. The watches (low
 * stock, units about to expire, tomorrow's drive) may be switched off.
 */
final class StaffNotificationTopics
{
    public const BLOOD_REQUESTS = 'blood_requests';

    public const SEROLOGY_VERIFICATION = 'serology_verification';

    public const LOW_STOCK = 'low_stock';

    public const CORRECTIONS = 'corrections';

    /** A referral is chased this many days after it was made. */
    public const REFERRAL_WAIT_DAYS = 3;

    /** Units are flagged this many days before they expire. */
    public const EXPIRY_WARNING_DAYS = 3;

    public const REFERRALS = 'referrals';

    public const EXPIRING_UNITS = 'expiring_units';

    public const DRIVE_REMINDERS = 'drive_reminders';

    /** The switches a staff member may turn off, with their defaults. */
    public const OPTIONAL = [
        self::LOW_STOCK => true,
        self::EXPIRING_UNITS => true,
        self::DRIVE_REMINDERS => true,
    ];

    /**
     * A hospital's request: the roles that decide on it or allocate against
     * it (a custom role in a department holding either counts), and the
     * Supervisor, who watches every department.
     */
    public static function receivesRequests(User $user): bool
    {
        if ($user->management_level === ManagementLevel::Supervisor) {
            return true;
        }

        $roles = [
            ...DepartmentPermissions::rolesHolding('requests.approve'),
            ...DepartmentPermissions::rolesHolding('requests.process'),
        ];

        if (array_intersect(self::values($user->staffRoles()), self::values($roles)) !== []) {
            return true;
        }

        $departments = [
            ...DepartmentPermissions::departmentsHolding('requests.approve'),
            ...DepartmentPermissions::departmentsHolding('requests.process'),
        ];

        return filled($user->custom_role)
            && $user->department !== null
            && in_array($user->department, $departments, true);
    }

    /** A reactive result to verify: the roles that verify serology. */
    public static function receivesSerology(User $user): bool
    {
        return array_intersect(
            self::values($user->staffRoles()),
            self::values(DepartmentPermissions::rolesHolding('lab.verify_serology'))
        ) !== [];
    }

    /** Low stock: whoever can see the centre's inventory, unless they turned it off. */
    public static function receivesLowStock(User $user): bool
    {
        return in_array('inventory.view', $user->abilities(), true)
            && self::wants($user, self::LOW_STOCK);
    }

    /** A correction to decide: the head role that approves for some department. */
    public static function receivesCorrections(User $user): bool
    {
        foreach (Department::cases() as $department) {
            if ($user->hasStaffRole($department->correctionApprover())) {
                return true;
            }
        }

        return false;
    }

    /** One correction: the head of the department whose record it is. */
    public static function decidesCorrection(User $user, CorrectionRequest $correction): bool
    {
        return $user->hasStaffRole($correction->subject->department()->correctionApprover());
    }

    /** Referrals nobody has acted on: whoever follows referrals up. */
    public static function receivesReferrals(User $user): bool
    {
        return in_array('lab.referrals', $user->abilities(), true);
    }

    /** Units about to expire: whoever can see the inventory, unless they turned it off. */
    public static function receivesExpiringUnits(User $user): bool
    {
        return in_array('inventory.view', $user->abilities(), true)
            && self::wants($user, self::EXPIRING_UNITS);
    }

    /** Tomorrow's drive: whoever runs drives, unless they turned it off. */
    public static function receivesDriveReminders(User $user): bool
    {
        return in_array('drives.manage', $user->abilities(), true)
            && self::wants($user, self::DRIVE_REMINDERS);
    }

    /**
     * The switches in force: what was stored, or the default.
     *
     * @return array<string, bool>
     */
    public static function preferences(User $user): array
    {
        $stored = $user->notification_preferences ?? [];

        return array_map(
            fn (string $key): bool => array_key_exists($key, $stored) ? (bool) $stored[$key] : self::OPTIONAL[$key],
            array_combine(array_keys(self::OPTIONAL), array_keys(self::OPTIONAL))
        );
    }

    public static function wants(User $user, string $key): bool
    {
        return self::preferences($user)[$key] ?? true;
    }

    /**
     * The notices this person gets, for the Settings screen: why they get each,
     * and whether it can be turned off.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function for(User $user): array
    {
        $topics = [];
        $supervisor = $user->management_level === ManagementLevel::Supervisor;

        if (self::receivesRequests($user)) {
            $topics[] = [
                'key' => self::BLOOD_REQUESTS,
                'label' => 'New blood requests',
                'description' => 'A hospital sends this centre a request for blood.',
                'reason' => $supervisor
                    ? 'You watch every department as Supervisor.'
                    : 'You decide on or allocate hospital requests.',
                'locked' => true,
                'enabled' => true,
            ];
        }

        if (self::receivesSerology($user)) {
            $topics[] = [
                'key' => self::SEROLOGY_VERIFICATION,
                'label' => 'Results to verify',
                'description' => 'A technologist records a reactive serology result that needs your verification.',
                'reason' => 'You verify reactive results.',
                'locked' => true,
                'enabled' => true,
            ];
        }

        if (self::receivesCorrections($user)) {
            $topics[] = [
                'key' => self::CORRECTIONS,
                'label' => 'Corrections to approve',
                'description' => 'Someone in your department asks to correct a record you approve for.',
                'reason' => 'You approve your department\'s corrections.',
                'locked' => true,
                'enabled' => true,
            ];
        }

        if (self::receivesReferrals($user)) {
            $topics[] = [
                'key' => self::REFERRALS,
                'label' => 'Referrals waiting',
                'description' => 'A counselling referral is still not acted on '.self::REFERRAL_WAIT_DAYS.' days after it was made.',
                'reason' => 'You follow up counselling referrals.',
                'locked' => true,
                'enabled' => true,
            ];
        }

        $inventory = in_array('inventory.view', $user->abilities(), true);

        if ($inventory) {
            $topics[] = [
                'key' => self::LOW_STOCK,
                'label' => 'Low stock',
                'description' => 'A blood type or component falls below the minimum set for this centre.',
                'reason' => 'You can see this centre\'s inventory.',
                'locked' => false,
                'enabled' => self::wants($user, self::LOW_STOCK),
            ];
            $topics[] = [
                'key' => self::EXPIRING_UNITS,
                'label' => 'Units about to expire',
                'description' => 'Each morning, the available units that expire within '.self::EXPIRY_WARNING_DAYS.' days.',
                'reason' => 'You can see this centre\'s inventory.',
                'locked' => false,
                'enabled' => self::wants($user, self::EXPIRING_UNITS),
            ];
        }

        if (in_array('drives.manage', $user->abilities(), true)) {
            $topics[] = [
                'key' => self::DRIVE_REMINDERS,
                'label' => 'Drive tomorrow',
                'description' => 'The morning before a mobile drive this centre holds.',
                'reason' => 'You run this centre\'s drives.',
                'locked' => false,
                'enabled' => self::wants($user, self::DRIVE_REMINDERS),
            ];
        }

        return $topics;
    }

    /**
     * @param  array<int, StaffRole>  $roles
     * @return array<int, string>
     */
    private static function values(array $roles): array
    {
        return array_map(fn (StaffRole $role): string => $role->value, $roles);
    }
}
