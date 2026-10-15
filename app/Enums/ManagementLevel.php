<?php

namespace App\Enums;

/**
 * The two levels a blood-centre account can hold above, or instead of, a staff role.
 *
 * Neither is a department post. They replace the single `is_supervisor` flag,
 * which used to hold every ability in the portal. It was split at the project
 * owner's request (2026-10-15) so that running the centre's accounts and
 * watching its numbers are no longer the same thing as doing every
 * department's work:
 *
 *  - Admin: staff accounts, roles and privileges, and the centre's own
 *    settings, with the dashboard and the reports. No department pages and no
 *    corrections.
 *  - Supervisor: the dashboard, the reports and the view-only monitoring
 *    pages, across every department. Records, approves and releases nothing.
 *
 * A level adds to a staff role rather than replacing it: an account holding
 * both does its role's work as well. What each level grants is declared in
 * DepartmentPermissions::forLevel(). Recorded in docs/IMPLEMENTATION_DECISIONS.md,
 * "Blood Center Admin and view-only Supervisor".
 */
enum ManagementLevel: string
{
    case Admin = 'admin';

    case Supervisor = 'supervisor';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Blood Center Admin',
            self::Supervisor => 'Supervisor',
        };
    }

    /**
     * Served to the staff form so whoever assigns a level reads the same
     * boundary the matrix enforces.
     */
    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Creates staff accounts, assigns roles and privileges, and sets the centre\'s logo. Sees the dashboard and reports. No department pages and no corrections.',
            self::Supervisor => 'Sees the dashboard, the reports and every department\'s monitoring pages: stock, thresholds, the daily stock report, incoming requests and statements. Cannot record, approve, release or manage anyone.',
        };
    }
}
