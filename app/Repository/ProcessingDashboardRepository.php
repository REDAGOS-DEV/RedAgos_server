<?php

namespace App\Repository;

use App\Enums\CorrectionSubject;
use App\Enums\DonationStatus;
use App\Models\AuditLog;
use App\Models\CorrectionRequest;
use App\Models\Donation;
use App\Models\DonationClearance;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The counts behind the Processing dashboard, all for one facility.
 *
 * When a unit was separated, handed over or rejected is read from the audit
 * trail, not from the rows themselves: a breakdown correction re-creates the
 * component rows, so their timestamps say when the breakdown was last
 * changed, not when the bag was separated. As on the Testing dashboard,
 * timestamps are compared against absolute bounds and grouped by day in the
 * service, in the operational timezone.
 */
class ProcessingDashboardRepository
{
    /**
     * Every drawn donation still with Processing: not handed over, not
     * rejected. With what the service needs to say where each one stands.
     *
     * @return Collection<int, Donation>
     */
    public function openDonations(int $facilityId): Collection
    {
        return Donation::query()
            ->where('facility_id', $facilityId)
            ->whereIn('status', [DonationStatus::Collected->value, DonationStatus::Tested->value])
            ->withExists('components')
            ->with(['clearances:id,donation_id,kind', 'collection:id,donation_id,ended_at'])
            ->get(['id', 'donation_date', 'status', 'labelled_at']);
    }

    /**
     * When each donation's breakdown was first recorded, for those first
     * recorded since `$from`: donation id => instant.
     *
     * @return SupportCollection<int, CarbonImmutable>
     */
    public function firstSeparatedSince(int $facilityId, CarbonInterface $from): SupportCollection
    {
        return AuditLog::query()
            ->where('facility_id', $facilityId)
            ->where('action', 'laboratory.components_declared')
            ->where('auditable_type', Donation::class)
            ->selectRaw('auditable_id, MIN(created_at) AS first_at')
            ->groupBy('auditable_id')
            ->havingRaw('MIN(created_at) >= ?', [$from])
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->auditable_id => CarbonImmutable::parse($row->first_at)])
            ->toBase();
    }

    /**
     * These donations with their bags (by component) and when they were drawn.
     *
     * @param  array<int, int>  $donationIds
     * @return Collection<int, Donation>
     */
    public function separatedDonations(array $donationIds): Collection
    {
        return Donation::query()
            ->whereIn('id', $donationIds)
            ->with(['components:id,donation_id,component_id,quantity', 'components.component:id,name', 'collection:id,donation_id,ended_at'])
            ->get(['id', 'donation_date']);
    }

    /**
     * Each hand-over to Issuance made here since `$from`: donation id => instant.
     *
     * @return SupportCollection<int, CarbonImmutable>
     */
    public function handOversSince(int $facilityId, CarbonInterface $from): SupportCollection
    {
        return AuditLog::query()
            ->where('facility_id', $facilityId)
            ->where('action', 'laboratory.donation_completed')
            ->where('auditable_type', Donation::class)
            ->where('created_at', '>=', $from)
            ->get(['auditable_id', 'created_at'])
            ->mapWithKeys(fn (AuditLog $row): array => [(int) $row->auditable_id => CarbonImmutable::parse($row->created_at)])
            ->toBase();
    }

    /**
     * Units Processing rejected here since `$from`. A reactive serology
     * result rejects a donation on its own, logged under the same action
     * with `automatic`; that one was Testing's, so it is left out.
     */
    public function processingRejectionsSince(int $facilityId, CarbonInterface $from): int
    {
        return AuditLog::query()
            ->where('facility_id', $facilityId)
            ->where('action', 'laboratory.donation_rejected')
            ->where('created_at', '>=', $from)
            ->get(['id', 'context'])
            ->reject(fn (AuditLog $row): bool => (bool) ($row->context['automatic'] ?? false))
            ->count();
    }

    /**
     * When each of these donations was fully cleared at the bench: donation
     * id => the later of its two live clearances. A clearance with no issuer
     * was backfilled, not earned at the bench, so such a donation is left out.
     *
     * @param  array<int, int>  $donationIds
     * @return SupportCollection<int, CarbonImmutable>
     */
    public function fullyClearedAt(array $donationIds, int $kinds): SupportCollection
    {
        return DonationClearance::query()
            ->whereIn('donation_id', $donationIds)
            ->whereNull('revoked_at')
            ->get(['donation_id', 'kind', 'issued_by', 'issued_at'])
            ->groupBy('donation_id')
            ->filter(fn ($tokens): bool => $tokens->count() >= $kinds && $tokens->every(fn ($token): bool => $token->issued_by !== null))
            ->map(fn ($tokens): CarbonImmutable => $tokens->map(fn ($token): CarbonImmutable => CarbonImmutable::parse($token->issued_at))->max())
            ->toBase();
    }

    /**
     * How many donations here had their final labels printed within the
     * bounds. A reprint moves the time, so a unit counts once, on its last print.
     */
    public function labelledBetween(int $facilityId, CarbonInterface $from, CarbonInterface $to): int
    {
        return Donation::query()
            ->where('facility_id', $facilityId)
            ->whereBetween('labelled_at', [$from, $to])
            ->count();
    }

    /**
     * Breakdown corrections waiting for the component technologist.
     */
    public function pendingComponentCorrections(int $facilityId): int
    {
        return CorrectionRequest::query()
            ->where('facility_id', $facilityId)
            ->where('status', 'pending')
            ->where('subject', CorrectionSubject::Components->value)
            ->count();
    }
}
