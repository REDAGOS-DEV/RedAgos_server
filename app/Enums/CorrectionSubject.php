<?php

namespace App\Enums;

use App\Http\Requests\CorrectDispatchRequest;
use App\Http\Requests\DeclareComponentsRequest;
use App\Http\Requests\RecordCollectionRequest;
use App\Http\Requests\RecordImmunohematologyRequest;
use App\Http\Requests\RecordPaymentRequest;
use App\Http\Requests\RecordScreeningRequest;
use App\Http\Requests\RecordSerologyRequest;
use App\Http\Requests\UpdateBloodUnitRequest;
use App\Http\Requests\VoidPaymentRequest;
use App\Models\User;
use App\Support\CorrectionValues;

/**
 * A saved record that may only be changed through an approved correction request.
 *
 * Once one of these exists its writer cannot simply save over it: they ask, and
 * someone else in the department decides. See "Correction requests" in
 * docs/IMPLEMENTATION_DECISIONS.md.
 */
enum CorrectionSubject: string
{
    case Screening = 'screening';

    case Collection = 'collection';

    case Immunohematology = 'immunohematology';

    case Serology = 'serology';

    case Components = 'components';

    case UnitDetails = 'unit_details';

    case Dispatch = 'dispatch';

    case Payment = 'payment';

    /**
     * Not a field changed but the payment itself voided: an entry made in
     * error, or money handed straight back, while its cash shift is still
     * open. Approved like any Billing correction; refunds stay outside RedAgos.
     */
    case PaymentVoid = 'payment_void';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The subjects that are about one kind of target.
     *
     * @return array<int, string>
     */
    public static function valuesFor(CorrectionTarget $target): array
    {
        return array_values(array_map(
            fn (self $subject): string => $subject->value,
            array_filter(self::cases(), fn (self $subject): bool => $subject->target() === $target)
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Screening => 'Screening',
            self::Collection => 'Collection record',
            self::Immunohematology => 'Blood typing',
            self::Serology => 'Serology panel',
            self::Components => 'Component breakdown',
            self::UnitDetails => 'Unit details',
            self::Dispatch => 'Dispatch record',
            self::Payment => 'Payment',
            self::PaymentVoid => 'Payment void',
        };
    }

    /**
     * What the record being corrected is, and so which column holds its key.
     */
    public function target(): CorrectionTarget
    {
        return match ($this) {
            self::Screening,
            self::Collection,
            self::Immunohematology,
            self::Serology,
            self::Components => CorrectionTarget::Donation,
            self::UnitDetails => CorrectionTarget::BloodUnit,
            self::Dispatch => CorrectionTarget::Allocation,
            self::Payment, self::PaymentVoid => CorrectionTarget::Payment,
        };
    }

    /**
     * The ability that writes this record, which a requester must hold.
     */
    public function writeAbility(): string
    {
        return match ($this) {
            self::Screening => 'donations.screen',
            self::Collection => 'donations.collect',
            self::Immunohematology => 'lab.record_immunohematology',
            self::Serology => 'lab.record_serology',
            self::Components => 'lab.record_components',
            self::UnitDetails => 'inventory.audit',
            self::Dispatch => 'requests.release',
            self::Payment, self::PaymentVoid => 'billing.record_payment',
        };
    }

    /**
     * The predefined roles that may file this correction, or null for any holder of the write ability.
     *
     * A department's custom role inherits the department's abilities, so the
     * ability alone would let it file a correction that belongs to a named
     * post. A supervisor files any of them.
     *
     * @return array<int, StaffRole>|null
     */
    public function filingRoles(): ?array
    {
        return match ($this) {
            self::UnitDetails => [StaffRole::ItDataClerk],
            self::Dispatch => [StaffRole::DispatchCoordinator, StaffRole::InventoryControlOfficer],
            self::Payment, self::PaymentVoid => [StaffRole::BillingClerk, StaffRole::BillingSupervisor],
            default => null,
        };
    }

    /**
     * Whether a user may file a correction of this subject.
     *
     * The one enforcement point. What the client shows is only a presentation
     * aid, and every filing route reaches CorrectionService::request(), which
     * asks this before it looks anything up.
     */
    public function mayBeFiledBy(User $user): bool
    {
        if (! $user->can('corrections.request') || ! $user->can($this->writeAbility())) {
            return false;
        }

        $roles = $this->filingRoles();

        if ($roles === null || $user->is_supervisor) {
            return true;
        }

        return $user->staff_role !== null && in_array($user->staff_role, $roles, true);
    }

    /**
     * The department the record belongs to, whose approver decides.
     */
    public function department(): Department
    {
        return match ($this) {
            self::Screening, self::Collection => Department::Collection,
            self::Immunohematology, self::Serology => Department::Testing,
            self::Components => Department::Processing,
            self::UnitDetails, self::Dispatch => Department::Issuance,
            self::Payment, self::PaymentVoid => Department::Billing,
        };
    }

    /**
     * The correctable fields of a subject that compares them in canonical form.
     *
     * Empty for the donation subjects, which keep the values as their own
     * writes validate them.
     *
     * @return array<string, string> Field => CorrectionValues type.
     */
    public function fieldTypes(): array
    {
        return match ($this) {
            self::UnitDetails => [
                'storage_location' => CorrectionValues::STRING,
                'expiry_date' => CorrectionValues::DATE,
            ],
            self::Dispatch => [
                'released_at' => CorrectionValues::DATETIME,
                'handed_to' => CorrectionValues::STRING,
            ],
            self::Payment => [
                'amount_paid' => CorrectionValues::MONEY,
                'payment_method' => CorrectionValues::STRING,
                'reference_number' => CorrectionValues::STRING,
            ],
            self::PaymentVoid => [
                'void' => CorrectionValues::BOOLEAN,
            ],
            default => [],
        };
    }

    /**
     * The form request whose rules the corrected values must pass — the same
     * ones the original write passed.
     *
     * @return class-string
     */
    public function requestClass(): string
    {
        return match ($this) {
            self::Screening => RecordScreeningRequest::class,
            self::Collection => RecordCollectionRequest::class,
            self::Immunohematology => RecordImmunohematologyRequest::class,
            self::Serology => RecordSerologyRequest::class,
            self::Components => DeclareComponentsRequest::class,
            self::UnitDetails => UpdateBloodUnitRequest::class,
            self::Dispatch => CorrectDispatchRequest::class,
            self::Payment => RecordPaymentRequest::class,
            self::PaymentVoid => VoidPaymentRequest::class,
        };
    }
}
