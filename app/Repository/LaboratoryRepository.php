<?php

namespace App\Repository;

use App\Enums\ClearanceKind;
use App\Enums\DonationStatus;
use App\Enums\ReferralStatus;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\DonationImmunohematology;
use App\Models\DonationSerology;
use App\Models\DonationTestResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Testing and Processing records for one facility's donations.
 *
 * Scoped to the facility throughout: a donation belongs to the centre it was
 * drawn at, and so does everything the laboratory records against it.
 */
class LaboratoryRepository
{
    /**
     * Relations every laboratory response is built from.
     *
     * @var array<int, string>
     */
    private const WITH = [
        'donorProfile.donor',
        'donorProfile.bloodType',
        'testResult.bloodType',
        'immunohematology.bloodType',
        'immunohematology.recorder',
        'serology.recorder',
        'collection',
        'screening.fingerprickBloodType',
        'components.component',
        'clearances',
    ];

    /**
     * Page the donations this facility's laboratory still has work on.
     *
     * `stage=testing` is the Testing department's queue: every drawn donation
     * still waiting on a section — plus any passed under the old
     * single-result screen that has no itemised panel yet. Without a stage it
     * is the queue Processing has always worked.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Donation>
     */
    public function paginateQueue(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return Donation::query()
            ->with(self::WITH)
            ->where('facility_id', $facilityId)
            ->when(
                isset($filters['segment_number']),
                fn (Builder $q): Builder => $q->whereHas(
                    'collection',
                    fn (Builder $c): Builder => $c->where('segment_number', $filters['segment_number'])
                )
            )
            ->when(
                isset($filters['status']),
                fn (Builder $q): Builder => $q->where('status', $filters['status']),
                fn (Builder $q): Builder => match ($filters['stage'] ?? null) {
                    // A testing department's queue: every drawn donation not
                    // rejected and still without that department's token —
                    // completed ones included, since Processing does not wait.
                    'serology' => $this->awaitingClearance($q, [ClearanceKind::Tti]),
                    'immunohematology' => $this->awaitingClearance($q, [ClearanceKind::Immunohematology]),
                    'testing' => $this->awaitingClearance($q, ClearanceKind::cases()),
                    // The laboratory's own queue by default: everything handed
                    // over by collection and not yet completed or rejected.
                    default => $q->whereIn('status', [
                        DonationStatus::Collected->value,
                        DonationStatus::Tested->value,
                    ]),
                }
            )
            ->orderBy('donation_date')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * Constrain to drawn donations still missing any of the given clearances.
     *
     * @param  array<int, ClearanceKind>  $kinds
     */
    private function awaitingClearance(Builder $query, array $kinds): Builder
    {
        return $query
            ->whereIn('status', [
                DonationStatus::Collected->value,
                DonationStatus::Tested->value,
                DonationStatus::Completed->value,
            ])
            ->where(function (Builder $missing) use ($kinds): void {
                foreach ($kinds as $kind) {
                    $missing->orWhereDoesntHave(
                        'clearances',
                        fn (Builder $clearance): Builder => $clearance->where('kind', $kind->value)
                    );
                }
            });
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
            ->with(self::WITH)
            ->where('id', $donationId)
            ->where('facility_id', $facilityId)
            ->first();
    }

    /**
     * Record or correct the overall outcome for a donation.
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

    public function testResultFor(int $donationId): ?DonationTestResult
    {
        return DonationTestResult::query()->where('donation_id', $donationId)->first();
    }

    /**
     * Record or correct the ABO/Rh typing. One row per donation.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertImmunohematology(int $donationId, array $attributes): DonationImmunohematology
    {
        return DonationImmunohematology::updateOrCreate(
            ['donation_id' => $donationId],
            $attributes
        );
    }

    public function immunohematologyFor(int $donationId): ?DonationImmunohematology
    {
        return DonationImmunohematology::query()->where('donation_id', $donationId)->first();
    }

    /**
     * Record or correct the serology panel. One row per donation.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertSerology(int $donationId, array $attributes): DonationSerology
    {
        return DonationSerology::updateOrCreate(
            ['donation_id' => $donationId],
            $attributes
        );
    }

    public function serologyFor(int $donationId): ?DonationSerology
    {
        return DonationSerology::query()->where('donation_id', $donationId)->first();
    }

    /**
     * Open the counselling referral for a donation rejected on serology.
     *
     * Keyed on the donation, so it can only ever be opened once.
     */
    public function openReferral(Donation $donation, int $facilityId): CounsellingReferral
    {
        return CounsellingReferral::firstOrCreate(
            ['donation_id' => $donation->id],
            [
                'donor_id' => $donation->donor_id,
                'facility_id' => $facilityId,
                'status' => ReferralStatus::Pending,
            ]
        );
    }

    /**
     * Replace the declared breakdown for a donation: one row per bag, with its volume.
     *
     * Every row carries `quantity` 1. The column stays because inventory's
     * ledger of declared bags is its sum, and breakdowns recorded before
     * volumes were kept still hold a real count there.
     *
     * @param  array<int, array{component_id: int, volume_ml: int}>  $components
     */
    public function replaceComponents(int $donationId, array $components, int $declaredBy): void
    {
        DonationComponent::query()->where('donation_id', $donationId)->delete();

        foreach ($components as $component) {
            DonationComponent::create([
                'donation_id' => $donationId,
                'component_id' => $component['component_id'],
                'quantity' => 1,
                'volume_ml' => $component['volume_ml'],
                'declared_by' => $declaredBy,
            ]);
        }
    }

    /**
     * The bags declared for a donation, in the order they were declared.
     *
     * @return Collection<int, DonationComponent>
     */
    public function componentsFor(int $donationId): Collection
    {
        return DonationComponent::query()
            ->with('component')
            ->where('donation_id', $donationId)
            ->orderBy('id')
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
