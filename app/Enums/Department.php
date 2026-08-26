<?php

namespace App\Enums;

enum Department: string
{
    case Collection = 'collection';

    case Laboratory = 'laboratory';

    case Inventory = 'inventory';

    case Billing = 'billing';

    /**
     * Get every accepted department value, in the order the organisation chart declares them.
     *
     * This is the canonical list. Validation rules and the API both project it,
     * so a staff account cannot be filed under a department the matrix has
     * never heard of.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the department's full name as docs/BLOOD-CENTER.md writes it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Collection => 'Donor / Collection',
            self::Laboratory => 'Laboratory / Processing',
            self::Inventory => 'Inventory / Storage & Blood Request / Release',
            self::Billing => 'Billing / Payment',
        };
    }
}
