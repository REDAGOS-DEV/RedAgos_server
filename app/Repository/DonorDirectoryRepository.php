<?php

namespace App\Repository;

use App\Enums\DonationStatus;
use App\Enums\RoleName;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\User;
use App\Support\AccountIdentity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
        return $this->donorQuery()
            ->where(fn (Builder $query): Builder => $query
                ->whereHas('donorProfile.appointments', fn (Builder $q) => $q->where('facility_id', $facilityId))
                ->orWhereHas('donorProfile.donations', fn (Builder $q) => $q->where('facility_id', $facilityId))
            )
            ->when(
                isset($filters['blood_type_id']),
                fn (Builder $q): Builder => $q->whereHas(
                    'donorProfile',
                    fn (Builder $p) => $p->where('blood_type_id', $filters['blood_type_id'])
                )
            )
            ->when(
                isset($filters['search']),
                fn (Builder $q): Builder => $this->applySearch($q, (string) $filters['search'])
            )
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate($perPage);
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

        return Donation::query()
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
     * @return array{total_donations: int, last_donation_at: ?string}
     */
    public function donationSummary(int $donorId): array
    {
        $completed = Donation::query()->where('donor_id', $donorId)->completed();

        return [
            'total_donations' => (clone $completed)->count(),
            'last_donation_at' => (clone $completed)->max('donation_date'),
        ];
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
            ->with('facility')
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
     * Apply a name/contact search within an already-restricted result set.
     *
     * Only ever called on the facility-scoped browse, never on the
     * cross-facility path.
     */
    private function applySearch(Builder $query, string $term): Builder
    {
        $like = '%'.$term.'%';

        return $query->where(function (Builder $inner) use ($like): void {
            $inner->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like);
        });
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
