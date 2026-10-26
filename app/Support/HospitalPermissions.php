<?php

namespace App\Support;

use App\Enums\HospitalRole;
use App\Models\User;

/**
 * The abilities behind every hospital blood bank `can:` gate.
 *
 * Added by the project owner on 2026-10-11 with HospitalRole. A hospital
 * account's role decides its set below; the Head holds every one. An account
 * with no hospital role holds none, which is why a blood-centre account never
 * carries these.
 *
 * Read from users.hospital_role alone: User::abilities() runs on every gate
 * check and every serialised user, and must not touch a relation.
 *
 * Every key starts `hospital.`, so it cannot collide with a blood-centre
 * ability or an `admin.` privilege when the gates are registered together.
 */
final class HospitalPermissions
{
    /**
     * What every hospital role may read: the requests and the stock.
     */
    private const SHARED = [
        'hospital.requests.view',
        'hospital.inventory.view',
    ];

    /**
     * Abilities granted by each role, on top of the shared ones.
     *
     * @var array<string, array<int, string>>
     */
    private const MATRIX = [
        HospitalRole::InventoryStaff->value => [
            // Step two of receiving: put an arrived bag on a shelf.
            'hospital.inventory.stock_in',

            // Tag, crossmatch, transfuse, release and return a bag.
            'hospital.inventory.handle',
            'hospital.inventory.discard',
            'hospital.inventory.thresholds',

            // Blood from outside RedAgos, booked in by hand.
            'hospital.receiving.direct',
        ],

        HospitalRole::RequestingStaff->value => [
            // Patient Transfusion Requests and weekly requests: raise, source,
            // cancel, and close what is no longer needed.
            'hospital.requests.manage',
            'hospital.schedules.manage',

            // Step one of receiving: the bags dispatched for a request arrived.
            'hospital.receipt.confirm',

            'hospital.billing.view',
        ],
    ];

    /**
     * Abilities only the Head holds.
     */
    private const HEAD_ONLY = [
        'hospital.staff.manage',
    ];

    /**
     * Every hospital ability, for registering the gates.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_values(array_unique([
            ...self::SHARED,
            ...array_merge(...array_values(self::MATRIX)),
            ...self::HEAD_ONLY,
        ]));
    }

    /**
     * The abilities a user holds through their hospital role.
     *
     * @return array<int, string>
     */
    public static function for(User $user): array
    {
        return $user->hospital_role === null ? [] : self::forRole($user->hospital_role);
    }

    /**
     * The abilities one hospital role carries.
     *
     * @return array<int, string>
     */
    public static function forRole(HospitalRole $role): array
    {
        if ($role === HospitalRole::Head) {
            return self::all();
        }

        return array_values(array_unique([...self::SHARED, ...self::MATRIX[$role->value]]));
    }
}
