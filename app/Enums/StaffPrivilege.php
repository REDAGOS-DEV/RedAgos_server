<?php

namespace App\Enums;

/**
 * The Read / Write / Update / Delete privileges a supervisor ticks per staff member.
 *
 * They cap a role rather than define it: a staff member holds only those of
 * their role's abilities whose kind is ticked. DepartmentPermissions::KIND
 * sorts every ability into one of the four.
 */
enum StaffPrivilege: string
{
    case Read = 'read';

    case Write = 'write';

    case Update = 'update';

    case Delete = 'delete';

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
            self::Read => 'Read',
            self::Write => 'Write',
            self::Update => 'Update',
            self::Delete => 'Delete',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Read => 'View records, queues and stock.',
            self::Write => 'Record new entries: donors, screenings, collections, results, components, stock, payments.',
            self::Update => 'Change a status: check in, complete, release, approve, correct.',
            self::Delete => 'Close a donation that cannot go ahead, or discard a unit.',
        };
    }
}
