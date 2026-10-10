<?php

namespace App\Enums;

use App\Models\BloodRequest;

/**
 * What a billing transaction was for, which decides who it is between.
 *
 * A Patient Transfusion is settled at the centre's counter by the patient or
 * their watcher. A weekly replenishment is billed to the hospital by
 * statement and settled outside RedAgos (owner, 2026-10-10). The two are never
 * added together: one is money the counter takes, the other a receivable.
 */
enum TransactionCategory: string
{
    case PatientTransfusion = 'patient_transfusion';

    case WeeklyReplenishment = 'weekly_replenishment';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The category of a request's bill, from what the request is for.
     */
    public static function forRequest(BloodRequest $request): self
    {
        return $request->request_purpose === RequestPurpose::Replenishment
            ? self::WeeklyReplenishment
            : self::PatientTransfusion;
    }

    public function label(): string
    {
        return match ($this) {
            self::PatientTransfusion => 'Patient transfusion',
            self::WeeklyReplenishment => 'Weekly replenishment',
        };
    }
}
