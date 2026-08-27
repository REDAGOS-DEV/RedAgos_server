<?php

namespace App\Repository;

use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\DonationTestResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Processing records for one facility's donations.
 *
 * Scoped to the facility throughout: a donation belongs to the centre it was
 * drawn at, and so does everything the laboratory records against it.
 */
class LaboratoryRepository
{
    /**
     * Page the donations this facility's laboratory still has work on.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Donation>
     */
    public function paginateQueue(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return Donation::query()
            ->with(['donorProfile.donor', 'donorProfile.bloodType', 'testResult', 'components.component'])
            ->where('facility_id', $facilityId)
            ->when(
                isset($filters['status']),
                fn (Builder $q): Builder => $q->where('status', $filters['status']),
                // The laboratory's own queue by default: everything handed over
                // by collection and not yet cleared or rejected.
                fn (Builder $q): Builder => $q->whereIn('status', [
                    DonationStatus::Collected->value,
                    DonationStatus::Tested->value,
                ])
            )
            ->orderBy('donation_date')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * Re-read one of this facility's donations under a row lock.
     */
    public function lockDonation(int $donationId, int $facilityId): ?Donation
    {
        return Donation::query()
            ->where('id', $donationId)
            ->where('facility_id', $facilityId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Find one of this facility's donations with everything the laboratory recorded.
     */
    public function findDonation(int $donationId, int $facilityId): ?Donation
    {
        return Donation::query()
            ->with([
                'donorProfile.donor',
                'donorProfile.bloodType',
                'testResult.bloodType',
                'components.component',
            ])
            ->where('id', $donationId)
            ->where('facility_id', $facilityId)
            ->first();
    }

    /**
     * Record or correct the screening outcome for a donation.
     *
     * `donation_test_results.donation_id` is unique, so a correction edits the
     * existing row rather than adding a second — there is never an ambiguity
     * about which result cleared the blood.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertTestResult(int $donationId, array $attributes): DonationTestResult
    {
        return DonationTestResult::updateOrCreate(
            ['donation_id' => $donationId],
            $attributes
        );
    }

    /**
     * Replace the declared component breakdown for a donation.
     *
     * @param  array<int, array{component_id: int, quantity: int}>  $components
     */
    public function replaceComponents(int $donationId, array $components, int $declaredBy): void
    {
        DonationComponent::query()->where('donation_id', $donationId)->delete();

        foreach ($components as $component) {
            DonationComponent::create([
                'donation_id' => $donationId,
                'component_id' => $component['component_id'],
                'quantity' => $component['quantity'],
                'declared_by' => $declaredBy,
            ]);
        }
    }

    /**
     * The components declared for a donation.
     *
     * @return Collection<int, DonationComponent>
     */
    public function componentsFor(int $donationId): Collection
    {
        return DonationComponent::query()
            ->with('component')
            ->where('donation_id', $donationId)
            ->get();
    }

    /**
     * Determine whether any component breakdown has been declared.
     */
    public function hasComponents(int $donationId): bool
    {
        return DonationComponent::query()->where('donation_id', $donationId)->exists();
    }
}
