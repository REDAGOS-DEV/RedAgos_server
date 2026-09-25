<?php

namespace App\Enums;

/**
 * The operational departments of a blood centre.
 *
 * Laboratory/Processing was split into Testing and Processing, and
 * Inventory/Storage & Blood Request/Release became Issuance. Recorded in
 * docs/IMPLEMENTATION_DECISIONS.md, "Blood-centre departments: five, not four".
 */
enum Department: string
{
    case Collection = 'collection';

    case Testing = 'testing';

    case Processing = 'processing';

    case Issuance = 'issuance';

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
            self::Collection => 'Collection',
            self::Testing => 'Testing',
            self::Processing => 'Processing',
            self::Issuance => 'Issuance',
            self::Billing => 'Billing / Payment',
        };
    }
}
