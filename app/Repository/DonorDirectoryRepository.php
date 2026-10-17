<?php

namespace App\Repository;

use App\Enums\DonationStatus;
use App\Enums\FacilityDonorStatus;
use App\Enums\RoleName;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\FacilityDonor;
use App\Models\User;
use App\Support\AccountIdentity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Donor lookup for blood-centre staff, in the two shapes the privacy rule allows.
 *
 * Donors are not owned by a facility — `donor_profiles` carries no facility_id
 * and a donor may book anywhere. So browsing is restricted to donors this
 * facility has actually dealt with, while an exact identifier finds anyone. The
 * second path exists because a walk-in who last donated elsewhere must be
 * findable: registering them again would fork their donation history and break
 * the 56-day interval check that reads it.
 */
class DonorDirectoryRepository
{
    /**
     * The donor-code format staff read off a card, e.g. DONOR-000015.
     */
    private const DONOR_CODE_PATTERN = '/^DONOR-(\d{1,10})$/i';

    /**
     * Page the donors this facility has actually dealt with.
     *
     * "Dealt with" means an appointment booked at, or a donation recorded at,
     * this facility. A donor who has only ever used another centre is absent
     * from this listing by design.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function browseForFacility(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->facilityDonorQuery($facilityId, $filters)->paginate($perPage);
    }

    /**
     * The donors this facility has dealt with, filtered and sorted as the
     * Donors page asks. Shared by the page, the export and the reminders, so
     * "the list" means the same rows in all three.
     *
     * "Dealt with" means an appointment booked here, a donation recorded here,
     * or a record of this centre's own (`facility_donors`: registered at its
     * counter, or given a status or a note here).
     *
     * Filters: search, blood_type_id, status (active|inactive), sort
     * (newest|oldest|name; newest by default).
     *
     * @param  array<string, mixed>  $filters
     */
    public function facilityDonorQuery(int $facilityId, array $filters = []): Builder
    {
        $query = $this->donorQuery()
            ->where(fn (Builder $query): Builder => $query
                ->whereHas('donorProfile.appointments', fn (Builder $q) => $q->where('facility_id', $facilityId))
                ->orWhereHas('donorProfile.donations', fn (Builder $q) => $q->where('facility_id', $facilityId))
                ->orWhereExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                    ->from('facility_donors')
                    ->whereColumn('facility_donors.donor_id', 'users.id')
                    ->where('facility_donors.facility_id', $facilityId))
            )
            ->when(
                isset($filters['blood_type_id']),
                fn (Builder $q): Builder => $q->whereHas(
                    'donorProfile',
                    fn (Builder $p) => $p->where('blood_type_id', $filters['blood_type_id'])
                )
            )
            ->when(
                isset($filters['status']),
                fn (Builder $q): Builder => $this->applyStatus($q, $facilityId, FacilityDonorStatus::from($filters['status']))
            )
            ->when(
                isset($filters['search']),
                fn (Builder $q): Builder => $this->applySearch($q, (string) $filters['search'])
            );

        return match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('id'),
            'name' => $query->orderBy('last_name')->orderBy('first_name')->orderBy('id'),
            default => $query->orderByDesc('id'),
        };
    }

    /**
     * This facility's own records for a set of donors, keyed by donor id.
     *
     * @param  array<int, int>  $donorIds
     * @return array<int, FacilityDonor>
     */
    public function facilityRecordsFor(int $facilityId, array $donorIds): array
    {
        if ($donorIds === []) {
            return [];
        }

        return FacilityDonor::query()
            ->where('facility_id', $facilityId)
            ->whereIn('donor_id', $donorIds)
            ->get()
            ->keyBy('donor_id')
            ->all();
    }

    /**
     * This facility's own record of one donor, if it has one.
     */
    public function facilityRecord(int $facilityId, int $donorId): ?FacilityDonor
    {
        return FacilityDonor::query()
            ->where('facility_id', $facilityId)
            ->where('donor_id', $donorId)
            ->first();
    }

    /**
     * Create or update this facility's record of a donor.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function saveFacilityRecord(int $facilityId, int $donorId, array $attributes): FacilityDonor
    {
        return FacilityDonor::query()->updateOrCreate(
            ['facility_id' => $facilityId, 'donor_id' => $donorId],
            $attributes
        );
    }

    /**
     * Mark donors as reminded by this facility, creating the record where
     * there was none.
     *
     * @param  array<int, int>  $donorIds
     */
    public function markReminded(int $facilityId, array $donorIds, Carbon $at): void
    {
        if ($donorIds === []) {
            return;
        }

        FacilityDonor::query()->upsert(
            array_map(fn (int $donorId): array => [
                'facility_id' => $facilityId,
                'donor_id' => $donorId,
                'status' => FacilityDonorStatus::Active->value,
                'registered_here' => false,
                'eligibility_reminded_at' => $at,
                'created_at' => $at,
                'updated_at' => $at,
            ], array_values($donorIds)),
            ['facility_id', 'donor_id'],
            ['eligibility_reminded_at', 'updated_at']
        );
    }

    /**
     * The visit each donor has open at this facility before any blood was
     * drawn (registered, or being screened), keyed by donor id.
     *
     * @param  array<int, int>  $donorIds
     * @return array<int, int> donor id => donation id
     */
    public function openVisitsBeforeDraw(int $facilityId, array $donorIds): array
    {
        if ($donorIds === []) {
            return [];
        }

        return Donation::query()
            ->where('facility_id', $facilityId)
            ->whereIn('donor_id', $donorIds)
            ->whereIn('status', [DonationStatus::Registered->value, DonationStatus::Screening->value])
            ->orderBy('id')
            ->pluck('id', 'donor_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Donation counts and dates for a page of donors, in one query.
     *
     * The bulk form of donationSummary(), plus how many of those donations
     * were at this facility. Same definition of a donation: blood was given.
     *
     * @param  array<int, int>  $donorIds
     * @return array<int, array{total_donations: int, last_drawn_at: ?string, donations_here: int}>
     */
    public function summariesFor(int $facilityId, array $donorIds): array
    {
        if ($donorIds === []) {
            return [];
        }

        $rows = $this->bloodGiven(DB::table('donations')->whereIn('donations.donor_id', $donorIds))
            ->groupBy('donations.donor_id')
            ->select([
                'donations.donor_id',
                DB::raw('COUNT(*) as total_donations'),
                DB::raw('MAX(donations.donation_date) as last_drawn_at'),
                DB::raw('SUM(CASE WHEN donations.facility_id = '.(int) $facilityId.' THEN 1 ELSE 0 END) as donations_here'),
            ])
            ->get()
            ->keyBy('donor_id');

        $summaries = [];

        foreach ($donorIds as $donorId) {
            $row = $rows->get($donorId);

            $summaries[$donorId] = [
                'total_donations' => (int) ($row->total_donations ?? 0),
                'last_drawn_at' => $row?->last_drawn_at !== null ? (string) $row->last_drawn_at : null,
                'donations_here' => (int) ($row->donations_here ?? 0),
            ];
        }

        return $summaries;
    }

    /**
     * Find one donor by an identifier precise enough to have been presented in person.
     *
     * Deliberately exact-match only. A partial or fuzzy match here would turn
     * the cross-facility path into a way to enumerate the whole donor register,
     * which is what the browse restriction exists to prevent.
     */
    public function findByExactIdentifier(string $type, string $value): ?User
    {
        $query = $this->donorQuery();

        return match ($type) {
            'donor_code' => ($id = $this->donorCodeToId($value)) === null ? null : $query->whereKey($id)->first(),
            'email' => $query->where('email', mb_strtolower(trim($value)))->first(),
            'phone' => $query->where('phone', trim($value))->first(),
            // Normalised on the way in as well as the way out: the stored value
            // has no case or separators, so a raw card number never matches.
            'valid_id_number' => $query->whereHas(
                'donorProfile',
                fn (Builder $p) => $p->where('valid_id_number', AccountIdentity::normalizeValidIdNumber($value) ?? $value)
            )->first(),
            default => null,
        };
    }

    /**
     * Find a donor by primary key, still constrained to actual donor accounts.
     */
    public function findDonor(string $uuid): ?User
    {
        return $this->donorQuery()->where('uuid', $uuid)->first();
    }

    /**
     * Determine whether this facility has dealt with this donor before.
     *
     * Decides which of the two response shapes the caller receives: a full
     * record, or the standardised cross-facility summary.
     */
    public function facilityHasRelationship(int $donorId, int $facilityId): bool
    {
        $hasAppointment = DonationAppointment::query()
            ->where('donor_id', $donorId)
            ->where('facility_id', $facilityId)
            ->exists();

        if ($hasAppointment) {
            return true;
        }

        $hasDonation = Donation::query()
            ->where('donor_id', $donorId)
            ->where('facility_id', $facilityId)
            ->exists();

        return $hasDonation || FacilityDonor::query()
            ->where('donor_id', $donorId)
            ->where('facility_id', $facilityId)
            ->exists();
    }

    /**
     * Summarise a donor's donation history across every facility.
     *
     * Counts and dates only. This is what a centre that has never met the donor
     * is allowed to see, so it must carry enough to judge eligibility and
     * nothing that identifies where they donated or what was recorded there.
     *
     * A donation counts once blood was given: the bag was drawn, or Processing
     * has completed it. Counting completed donations alone told a donor bled
     * this morning that they were eligible, because the bag was still in the
     * laboratory, and the counter's own interval check (EligibilityRuleEvaluator,
     * reading the draw) would then turn them away. The eligibility reminder
     * must never be sent on that reading.
     *
     * @return array{total_donations: int, last_donation_at: ?string}
     */
    public function donationSummary(int $donorId): array
    {
        $given = $this->bloodGiven(DB::table('donations')->where('donations.donor_id', $donorId));

        $lastDrawnAt = (clone $given)->max('donations.donation_date');

        return [
            'total_donations' => (clone $given)->count(),
            'last_donation_at' => $lastDrawnAt !== null ? (string) $lastDrawnAt : null,
        ];
    }

    /**
     * Narrow a donations query to the visits where blood was actually given.
     */
    private function bloodGiven(QueryBuilder $query): QueryBuilder
    {
        return $query->where(fn (QueryBuilder $q) => $q
            ->where('donations.status', DonationStatus::Completed->value)
            ->orWhereExists(fn (QueryBuilder $collection) => $collection
                ->select(DB::raw(1))
                ->from('blood_collections')
                ->whereColumn('blood_collections.donation_id', 'donations.id'))
        );
    }

    /**
     * List a donor's donations at one facility, in detail.
     *
     * Scoped to the calling facility: detailed records stay with the centre
     * that created them.
     *
     * @return Collection<int, Donation>
     */
    public function donationsAtFacility(int $donorId, int $facilityId)
    {
        return Donation::query()
            ->with(['facility', 'screening', 'collection', 'components.component', 'counsellingReferral', 'testResult.bloodType'])
            ->where('donor_id', $donorId)
            ->where('facility_id', $facilityId)
            ->orderByDesc('donation_date')
            ->get();
    }

    /**
     * Count a donor's donations that are still in progress at this facility.
     *
     * Used to refuse opening a second donation for someone already mid-visit.
     */
    public function openDonationAtFacility(int $donorId, int $facilityId): ?Donation
    {
        return Donation::query()
            ->where('donor_id', $donorId)
            ->where('facility_id', $facilityId)
            ->whereIn('status', [
                DonationStatus::Registered->value,
                DonationStatus::Screening->value,
                DonationStatus::Collected->value,
                DonationStatus::Tested->value,
            ])
            ->first();
    }

    /**
     * The base query: real donor accounts only, with the profile eager-loaded.
     */
    private function donorQuery(): Builder
    {
        return User::query()
            ->with(['donorProfile.bloodType'])
            ->whereHas('donorProfile')
            ->whereHas('roles', fn (Builder $q) => $q->where('name', RoleName::Donor->value));
    }

    /**
     * Apply a name, contact or ID search within an already-restricted result set.
     *
     * Only ever called on the facility-scoped browse, never on the
     * cross-facility path. Case-insensitive on PostgreSQL as well, whose LIKE
     * is not. A full name matches across the two columns ("Ana Cruz"); a
     * donor code matches its own donor; a phone typed the local way (0917…)
     * matches the stored +63 form; and a valid ID number matches exactly.
     */
    private function applySearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $like = '%'.mb_strtolower($term).'%';
        $phone = preg_replace('/[\s-]+/', '', $term) ?? $term;
        $localPhone = preg_match('/^0(9\d*)$/', $phone, $m) === 1 ? '%+63'.$m[1].'%' : null;
        $donorId = $this->donorCodeToId($term);
        $validId = AccountIdentity::normalizeValidIdNumber($term);

        return $query->where(function (Builder $inner) use ($like, $phone, $localPhone, $donorId, $validId): void {
            $inner->whereRaw('LOWER(first_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])
                ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                ->orWhere('phone', 'like', '%'.$phone.'%')
                ->when($localPhone !== null, fn (Builder $q) => $q->orWhere('phone', 'like', $localPhone))
                ->when($donorId !== null, fn (Builder $q) => $q->orWhere('users.id', $donorId))
                ->when(
                    filled($validId),
                    fn (Builder $q) => $q->orWhereHas('donorProfile', fn (Builder $p) => $p->where('valid_id_number', $validId))
                );
        });
    }

    /**
     * Keep the donors with this status at this facility. No record there
     * means active.
     */
    private function applyStatus(Builder $query, int $facilityId, FacilityDonorStatus $status): Builder
    {
        $inactive = fn (QueryBuilder $q) => $q->select(DB::raw(1))
            ->from('facility_donors')
            ->whereColumn('facility_donors.donor_id', 'users.id')
            ->where('facility_donors.facility_id', $facilityId)
            ->where('facility_donors.status', FacilityDonorStatus::Inactive->value);

        return $status === FacilityDonorStatus::Inactive
            ? $query->whereExists($inactive)
            : $query->whereNotExists($inactive);
    }

    /**
     * Translate a printed donor code back to the account id it encodes.
     *
     * `donor_code` is a display format derived from users.id rather than a
     * stored column, so this is the inverse of DonorService's formatter.
     */
    private function donorCodeToId(string $code): ?int
    {
        if (preg_match(self::DONOR_CODE_PATTERN, trim($code), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
