<?php

namespace App\Support;

use App\Models\User;

/**
 * The donor block of a laboratory or inventory payload, blinded where the viewer's role requires it.
 *
 * Processing, TTI Testing, Immunohematology and the inventory roles work the
 * bag, not the person: they match a sample to its record by donation barcode and
 * donation id. Naming the donor on those screens adds nothing to that work and
 * is exactly what "blind processing" rules out, so a viewer without
 * donors.view_identity is told the donor's blood type — which is on the bag —
 * and nothing that identifies them.
 *
 * The roles that meet the donor in person hold the ability: the counter, the
 * screening physician, the chair, and the laboratory supervisor who works the
 * counselling referrals.
 */
final class DonorBlinding
{
    /**
     * @return array<string, mixed>|null
     */
    public static function block(?User $donor, ?string $bloodType, User $viewer): ?array
    {
        if ($donor === null) {
            return null;
        }

        if (! $viewer->can('donors.view_identity')) {
            return [
                'blinded' => true,
                'blood_type' => $bloodType,
            ];
        }

        return [
            'blinded' => false,
            'uuid' => $donor->uuid,
            'donor_code' => 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT),
            'full_name' => trim($donor->first_name.' '.$donor->last_name),
            'blood_type' => $bloodType,
        ];
    }
}
