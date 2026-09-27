<?php

namespace App\Enums;

/**
 * The predefined posts a blood-centre staff member can be given.
 *
 * A predefined role carries an exact ability set in DepartmentPermissions, and
 * its department follows from it. A staff member may instead hold a custom,
 * typed role (users.custom_role) inside a department they are assigned to
 * directly; that account gets its department's ability set. Either way the
 * account's Read / Write / Update / Delete privileges cap what it may do.
 *
 * Recorded in docs/IMPLEMENTATION_DECISIONS.md, "Staff roles".
 */
enum StaffRole: string
{
    case ScreeningPhysician = 'screening_physician';

    case Phlebotomist = 'phlebotomist';

    case ApheresisSpecialist = 'apheresis_specialist';

    case MedicalReceptionist = 'medical_receptionist';

    case ComponentTechnologist = 'component_technologist';

    case ProcessingAssistant = 'processing_assistant';

    case SerologyTechnologist = 'serology_technologist';

    case LabSupervisor = 'lab_supervisor';

    case InventoryControlOfficer = 'inventory_control_officer';

    case DispatchCoordinator = 'dispatch_coordinator';

    case ItDataClerk = 'it_data_clerk';

    case BillingClerk = 'billing_clerk';

    /**
     * Get every accepted role value, grouped in organisation-chart order.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the roles that sit in one department, in declaration order.
     *
     * @return array<int, self>
     */
    public static function forDepartment(Department $department): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role): bool => $role->department() === $department
        ));
    }

    /**
     * Find a predefined role by its value or its title, as typed in the staff form.
     *
     * Case-insensitive, so "phlebotomist / registered nurse" picks the
     * predefined role rather than creating a custom one of the same name.
     */
    public static function fromTyped(?string $typed): ?self
    {
        $needle = mb_strtolower(trim((string) $typed));

        if ($needle === '') {
            return null;
        }

        foreach (self::cases() as $role) {
            if ($role->value === $needle || mb_strtolower($role->label()) === $needle) {
                return $role;
            }
        }

        return null;
    }

    /**
     * The role a pre-role account in the given department is migrated to.
     *
     * Chosen as the least-privileged role that still does the department's
     * core work, so the backfill never widens what an account may do.
     */
    public static function defaultFor(Department $department): self
    {
        return match ($department) {
            Department::Collection => self::Phlebotomist,
            Department::Processing => self::ComponentTechnologist,
            Department::Testing => self::SerologyTechnologist,
            Department::Issuance => self::InventoryControlOfficer,
            Department::Billing => self::BillingClerk,
        };
    }

    /**
     * Get the department this role sits in.
     */
    public function department(): Department
    {
        return match ($this) {
            self::ScreeningPhysician,
            self::Phlebotomist,
            self::ApheresisSpecialist,
            self::MedicalReceptionist => Department::Collection,

            self::ComponentTechnologist,
            self::ProcessingAssistant => Department::Processing,

            self::SerologyTechnologist,
            self::LabSupervisor => Department::Testing,

            self::InventoryControlOfficer,
            self::DispatchCoordinator,
            self::ItDataClerk => Department::Issuance,

            self::BillingClerk => Department::Billing,
        };
    }

    /**
     * Get the role's title as the organisation chart writes it.
     */
    public function label(): string
    {
        return match ($this) {
            self::ScreeningPhysician => 'Donor Screening Physician',
            self::Phlebotomist => 'Phlebotomist / Registered Nurse',
            self::ApheresisSpecialist => 'Apheresis Specialist',
            self::MedicalReceptionist => 'Donor Care / Medical Receptionist',
            self::ComponentTechnologist => 'Component Laboratory Medical Technologist',
            self::ProcessingAssistant => 'Processing Laboratory Assistant',
            self::SerologyTechnologist => 'Serology / Molecular Medical Technologist',
            self::LabSupervisor => 'Laboratory Supervisor',
            self::InventoryControlOfficer => 'Inventory Control Officer',
            self::DispatchCoordinator => 'Dispatch / Transport Coordinator',
            self::ItDataClerk => 'IT Data Entry Clerk',
            self::BillingClerk => 'Billing Clerk',
        };
    }

    /**
     * Get a one-line statement of what the role does and what it is kept from.
     *
     * Served to the staff form so a supervisor assigning a role reads the same
     * boundary the matrix enforces.
     */
    public function description(): string
    {
        return match ($this) {
            self::ScreeningPhysician => 'Reviews health history and the questionnaire, examines the donor, and accepts or defers. Reads final lab results but cannot change them.',
            self::Phlebotomist => 'Records the collection: bag, segment, times and adverse reactions. Cannot alter the questionnaire or clear a deferred donor.',
            self::ApheresisSpecialist => 'Everything a phlebotomist records, plus apheresis procedures.',
            self::MedicalReceptionist => 'Registers donors, verifies ID, checks in arrivals, books appointments and schedules drives. No medical history, deferral reasons or lab results.',
            self::ComponentTechnologist => 'Separates whole blood into components and completes or rejects the donation. Works by barcode, blind to donor identity.',
            self::ProcessingAssistant => 'Declares bags and volumes and prepares labels. Cannot complete or release a donation.',
            self::SerologyTechnologist => 'Records the TTI panel and the ABO/Rh typing by barcode, blind to donor identity. Cannot undo a reactive result.',
            self::LabSupervisor => 'Records and oversees testing, approves Testing corrections and works counselling referrals. Cannot delete a reactive result.',
            self::InventoryControlOfficer => 'Books units in, manages stock, allocates to requests, and releases units from quarantine once testing has cleared them.',
            self::DispatchCoordinator => 'Handles hospital orders and releases units for transport. No clinical or laboratory access.',
            self::ItDataClerk => 'Audits inventory records against scanned barcodes. Cannot alter medical data, results or patient links.',
            self::BillingClerk => 'Raises bills against fulfilled requests and records payments.',
        };
    }
}
