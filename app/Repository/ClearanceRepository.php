<?php

namespace App\Repository;

use App\Enums\ClearanceKind;
use App\Models\DonationClearance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Persistence for clearance tokens.
 *
 * Callers issue and revoke under the donation's row lock, which is what keeps
 * at most one unrevoked token per (donation, kind).
 */
class ClearanceRepository
{
    /**
     * Issue a token, or return the one already in force. Never replaces it.
     */
    public function issue(int $donationId, ClearanceKind $kind, ?User $issuer, string $source): DonationClearance
    {
        return $this->active($donationId, $kind)->first()
            ?? DonationClearance::create([
                'donation_id' => $donationId,
                'kind' => $kind,
                'issued_by' => $issuer?->id,
                'issued_at' => now(),
                'source' => $source,
            ]);
    }

    /**
     * Revoke the token in force, when an approved correction replaces its result.
     */
    public function revoke(int $donationId, ClearanceKind $kind, User $by, string $reason): void
    {
        foreach ($this->active($donationId, $kind)->get() as $clearance) {
            $clearance->revoked_at = now();
            $clearance->revoked_by = $by->id;
            $clearance->revoked_reason = $reason;
            $clearance->save();
        }
    }

    public function has(int $donationId, ClearanceKind $kind): bool
    {
        return $this->active($donationId, $kind)->exists();
    }

    public function hasBoth(int $donationId): bool
    {
        return $this->kindsFor($donationId) === ClearanceKind::cases();
    }

    /**
     * The kinds in force for a donation, in enum order.
     *
     * @return array<int, ClearanceKind>
     */
    public function kindsFor(int $donationId): array
    {
        $issued = DonationClearance::query()
            ->where('donation_id', $donationId)
            ->whereNull('revoked_at')
            ->pluck('kind')
            ->map(fn (ClearanceKind|string $kind): string => $kind instanceof ClearanceKind ? $kind->value : $kind)
            ->all();

        return array_values(array_filter(
            ClearanceKind::cases(),
            fn (ClearanceKind $kind): bool => in_array($kind->value, $issued, true)
        ));
    }

    /**
     * @return Builder<DonationClearance>
     */
    private function active(int $donationId, ClearanceKind $kind): Builder
    {
        return DonationClearance::query()
            ->where('donation_id', $donationId)
            ->where('kind', $kind->value)
            ->whereNull('revoked_at');
    }
}
