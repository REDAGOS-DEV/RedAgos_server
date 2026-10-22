<?php

namespace App\Support;

use App\Models\Donation;
use Carbon\CarbonImmutable;

/**
 * A bag's expiry: the day it was collected plus its component's shelf life.
 *
 * One rule for the two places a bag's expiry is set: Processing's final label
 * (FinalLabelService) and Issuance's Stock Intake, which offers the same date
 * so the stock record and the label on the bag agree.
 */
final class BagExpiry
{
    /**
     * The expiry as Y-m-d, or null when the donation has no date or the
     * component no shelf life at this facility.
     *
     * Counted from the operational day the donation was drawn on, not its UTC
     * date: a bag drawn at 07:00 Manila was drawn the day before in UTC.
     */
    public static function date(Donation $donation, ?int $shelfLifeDays): ?string
    {
        if ($shelfLifeDays === null || $donation->donation_date === null) {
            return null;
        }

        return CarbonImmutable::parse(OperationalDay::dateOf($donation->donation_date))
            ->addDays($shelfLifeDays)
            ->toDateString();
    }
}
