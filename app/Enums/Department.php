<?php

namespace App\Enums;

/**
 * The operational departments of a blood centre.
 *
 * Five, as the staff form lists them. A staff member holding a predefined
 * StaffRole takes that role's department; one holding a custom role is
 * assigned a department directly. Recorded in
 * docs/IMPLEMENTATION_DECISIONS.md, "Staff roles".
 */
enum Department: string
{
    case Collection = 'collection';

    case Processing = 'processing';

    case Testing = 'testing';

    case Issuance = 'issuance';

    case Billing = 'billing';

    /**
     * Get every accepted department value, in the order the staff form lists them.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the department's name as the staff form writes it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Collection => 'Donor/Collection',
            self::Processing => 'Processing',
            self::Testing => 'Testing',
            self::Issuance => 'Issuance',
            self::Billing => 'Billing',
        };
    }

    /**
     * The role that approves correction requests for this department's records.
     *
     * Every department's senior post. For an approver's own request the Center
     * Admin (a supervisor) decides instead — see CorrectionService.
     */
    public function correctionApprover(): StaffRole
    {
        return match ($this) {
            self::Collection => StaffRole::ScreeningPhysician,
            self::Processing => StaffRole::ComponentTechnologist,
            self::Testing => StaffRole::LabSupervisor,
            self::Issuance => StaffRole::InventoryControlOfficer,
            self::Billing => StaffRole::BillingSupervisor,
        };
    }

    /**
     * Get the roles that sit in this department.
     *
     * @return array<int, StaffRole>
     */
    public function roles(): array
    {
        return StaffRole::forDepartment($this);
    }
}
