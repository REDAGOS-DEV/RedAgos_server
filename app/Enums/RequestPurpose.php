<?php

namespace App\Enums;

/**
 * Why a blood request was raised.
 *
 * This is a separate axis from UrgencyLevel and the two must never be merged: a
 * replenishment order can be STAT and a transfusion for a named patient can be
 * routine. The purpose decides whether the request carries patient identity at
 * all — a restock has no patient, so the DOH form's patient block is printed
 * blank rather than filled.
 */
enum RequestPurpose: string
{
    case PatientTransfusion = 'patient_transfusion';

    case Replenishment = 'replenishment';

    /**
     * Get every accepted purpose value, in the order the column declares them.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label shown on request forms and queues.
     */
    public function label(): string
    {
        return match ($this) {
            self::PatientTransfusion => 'Patient Transfusion',
            self::Replenishment => 'Blood Bank Replenishment',
        };
    }

    /**
     * Determine whether requests of this purpose carry patient identity.
     */
    public function requiresPatient(): bool
    {
        return $this === self::PatientTransfusion;
    }
}
