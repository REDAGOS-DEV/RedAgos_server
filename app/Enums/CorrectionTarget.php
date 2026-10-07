<?php

namespace App\Enums;

/**
 * What a correction request is about, which decides where its target is stored.
 *
 * Each target has its own nullable foreign key on correction_requests rather
 * than one shared morph column: a unit's key is text and the rest are bigint.
 * Exactly one is set on every row.
 */
enum CorrectionTarget: string
{
    case Donation = 'donation';

    case BloodUnit = 'blood_unit';

    case Allocation = 'allocation';

    case Payment = 'payment';

    /**
     * The correction_requests column that holds this target's key.
     */
    public function column(): string
    {
        return match ($this) {
            self::Donation => 'donation_id',
            self::BloodUnit => 'blood_unit_id',
            self::Allocation => 'request_allocation_id',
            self::Payment => 'payment_id',
        };
    }

    /**
     * The public property a form request exposes so its rules can ignore the
     * record being corrected, e.g. a barcode or reference that must stay unique.
     */
    public function correctingProperty(): string
    {
        return match ($this) {
            self::Donation => 'correctingDonationId',
            self::BloodUnit => 'correctingUnitId',
            self::Allocation => 'correctingAllocationId',
            self::Payment => 'correctingPaymentId',
        };
    }

    /**
     * The refusal for a target that does not exist at the requester's facility.
     *
     * @return array{code: string, message: string}
     */
    public function notFound(): array
    {
        return match ($this) {
            self::Donation => ['code' => 'donation_not_found', 'message' => 'That donation was not found at your facility.'],
            self::BloodUnit => ['code' => 'unit_not_found', 'message' => 'Blood unit not found.'],
            self::Allocation => ['code' => 'allocation_not_found', 'message' => 'Dispatch record not found.'],
            self::Payment => ['code' => 'payment_not_found', 'message' => 'Payment not found.'],
        };
    }
}
