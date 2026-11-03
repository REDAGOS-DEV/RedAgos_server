<?php

namespace App\Repository;

use App\Enums\AppointmentStatus;
use App\Enums\IdentityStatus;
use App\Enums\RoleName;
use App\Models\BloodType;
use App\Models\DonorProfile;
use App\Models\FacilityDonor;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountIdentity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DonorRepository
{
    public function createDonor(array $payload): User
    {
        return User::create($payload);
    }

    public function createDonorProfile(array $payload): DonorProfile
    {
        return DonorProfile::create($payload);
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function findByPhone(string $phone): ?User
    {
        return User::where('phone', $phone)->first();
    }

    public function existsEmail(string $email): bool
    {
        return User::where('email', $email)->exists();
    }

    public function existsPhone(string $phone): bool
    {
        return User::where('phone', $phone)->exists();
    }

    public function findBloodTypeByCode(string $code): ?BloodType
    {
        return BloodType::where('code', $code)->first();
    }

    public function findOrCreateRoleByName(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name]);
    }

    public function attachRole(User $user, Role $role): void
    {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    public function loadDonorRegistration(User $user): User
    {
        return $user->load(['roles', 'donorProfile.bloodType']);
    }

    public function loadDashboardUser(User $user): User
    {
        return $user->load(['roles', 'donorProfile.bloodType']);
    }

    public function updateUser(User $user, array $payload): User
    {
        $user->update($payload);

        return $user;
    }

    /**
     * Revoke email verification after the address on the account changed.
     *
     * `email_verified_at` is deliberately not fillable, so this cannot go
     * through updateUser().
     */
    public function markEmailUnverified(User $user): User
    {
        $user->forceFill(['email_verified_at' => null])->save();

        return $user;
    }

    public function updateDonorProfile(DonorProfile $profile, array $payload): DonorProfile
    {
        $profile->update($payload);

        return $profile;
    }

    /**
     * Find a donor by their public identifier, with the identity relations the
     * review queue reads.
     */
    public function findDonorByUuid(string $uuid): ?User
    {
        return User::with(['donorProfile.bloodType', 'donorProfile.identityReviewer'])
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * Re-read a donor profile under a row lock, for the identity workflow.
     *
     * Both the donor's submission and the administrator's decision take this
     * lock, so the two cannot interleave and approve a document that has since
     * been replaced.
     */
    public function lockDonorProfile(int $donorId): DonorProfile
    {
        return DonorProfile::whereKey($donorId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Page through donors whose identity document is in a given state.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function identitySubmissionsByStatus(IdentityStatus $status, int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->whereHas('donorProfile', fn (Builder $profile) => $profile->where('identity_status', $status->value))
            ->with(['donorProfile.bloodType', 'donorProfile.identityReviewer'])
            ->join('donor_profiles', 'donor_profiles.donor_id', '=', 'users.id')
            ->orderBy('donor_profiles.identity_submitted_at')
            ->select('users.*')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Other donor accounts that may belong to the same person as one of these
     * donors: the same date of birth with the same first name, last name, or
     * both. Birthday and surname is how a married donor's maiden-name record
     * turns up; birthday and first name, a misspelt surname.
     *
     * The ID number, email and phone are unique, so a second account always
     * differs on those. The birthday is required on every account (portal,
     * counter and record edits alike) and the same person cannot change it
     * between two sign-ups. A name alone is not enough: a common one would list
     * every namesake in the network.
     *
     * One query for the whole page, an OR group per donor. The donors
     * themselves come back too, and are only dropped from their own matches
     * (DonorIdentityVerificationService): two accounts awaiting review at once
     * are the likeliest pair of all.
     *
     * @param  iterable<int, User>  $donors  with donorProfile loaded
     * @return EloquentCollection<int, User>
     */
    public function lookalikeDonors(iterable $donors): EloquentCollection
    {
        $first = 'LOWER(TRIM(users.first_name)) = ?';
        $last = 'LOWER(TRIM(users.last_name)) = ?';
        $clauses = 0;

        $query = User::query()
            ->with(['donorProfile'])
            ->join('donor_profiles', 'donor_profiles.donor_id', '=', 'users.id')
            ->whereNull('donor_profiles.deleted_at')
            ->whereHas('roles', fn (Builder $q) => $q->where('name', RoleName::Donor->value))
            ->select('users.*');

        $query->where(function (Builder $any) use ($donors, $first, $last, &$clauses): void {
            foreach ($donors as $donor) {
                $firstName = AccountIdentity::comparableName($donor->first_name);
                $lastName = AccountIdentity::comparableName($donor->last_name);
                $born = $donor->donorProfile?->birth_date?->toDateString();

                if ($born === null || ($firstName === '' && $lastName === '')) {
                    continue;
                }

                $any->orWhere(fn (Builder $same) => $same
                    ->whereDate('donor_profiles.birth_date', $born)
                    ->where(fn (Builder $name) => $name
                        ->whereRaw($first, [$firstName])
                        ->orWhereRaw($last, [$lastName])));

                $clauses++;
            }
        });

        return $clauses === 0 ? new EloquentCollection : $query->get();
    }

    /**
     * The centre whose counter created each of these donor accounts, by donor
     * id. A donor without one signed up on the portal.
     *
     * @param  array<int, int>  $donorIds
     * @return Collection<int, string>
     */
    public function counterRegistrations(array $donorIds): Collection
    {
        if ($donorIds === []) {
            return collect();
        }

        return FacilityDonor::query()
            ->with('facility:id,name')
            ->whereIn('donor_id', $donorIds)
            ->where('registered_here', true)
            ->get()
            ->mapWithKeys(fn (FacilityDonor $record): array => [
                (int) $record->donor_id => (string) $record->facility?->name,
            ]);
    }

    /**
     * How many times each of these donors gave blood, and when last, by donor
     * id. Counted as countCollectedDonations() counts: a bag was drawn.
     *
     * @param  array<int, int>  $donorIds
     * @return Collection<int, array{total: int, last_at: ?string}>
     */
    public function collectedDonationSummaries(array $donorIds): Collection
    {
        if ($donorIds === []) {
            return collect();
        }

        return DB::table('donations')
            ->whereIn('donations.donor_id', $donorIds)
            ->whereExists(fn (QueryBuilder $collection) => $collection
                ->select(DB::raw(1))
                ->from('blood_collections')
                ->whereColumn('blood_collections.donation_id', 'donations.id'))
            ->groupBy('donations.donor_id')
            ->select('donations.donor_id', DB::raw('COUNT(*) as total'), DB::raw('MAX(donations.donation_date) as last_at'))
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->donor_id => [
                    'total' => (int) $row->total,
                    'last_at' => $row->last_at !== null ? Carbon::parse($row->last_at)->toDateString() : null,
                ],
            ]);
    }

    /**
     * Count the donations the donor actually gave blood for.
     *
     * Keyed on the `blood_collections` row, not on `status = completed`, for
     * the reason EligibilityRepository::lastBloodDrawnAt() gives: a draw is
     * recorded as `collected`, and only Processing moves it to `completed`,
     * days later or never. Counting `completed` left a donor who gave blood
     * this morning reading zero donations on their own dashboard.
     */
    public function countCollectedDonations(int $donorId): int
    {
        return $this->collectedDonations($donorId)->count();
    }

    public function findUpcomingAppointment(int $donorId, Carbon $now): ?object
    {
        return DB::table('donation_appointments')
            ->join('facilities', 'facilities.id', '=', 'donation_appointments.facility_id')
            ->where('donation_appointments.donor_id', $donorId)
            ->whereIn('donation_appointments.status', AppointmentStatus::activeValues())
            ->where('donation_appointments.appointment_datetime', '>=', $now)
            ->orderBy('donation_appointments.appointment_datetime')
            ->select([
                'donation_appointments.id',
                'donation_appointments.appointment_datetime',
                'donation_appointments.status',
                'facilities.name as facility_name',
            ])
            ->first();
    }

    public function recentDonations(int $donorId, int $limit = 5): Collection
    {
        return DB::table('donations')
            ->join('facilities', 'facilities.id', '=', 'donations.facility_id')
            ->leftJoin('donor_profiles', 'donor_profiles.donor_id', '=', 'donations.donor_id')
            ->leftJoin('blood_types', 'blood_types.id', '=', 'donor_profiles.blood_type_id')
            ->where('donations.donor_id', $donorId)
            ->latest('donations.donation_date')
            ->limit($limit)
            ->select([
                'donations.id',
                'donations.donation_date',
                'donations.status',
                'donations.appointment_id',
                'facilities.name as facility_name',
                'blood_types.code as blood_type',
            ])
            ->get();
    }

    /**
     * Count collected donations per calendar month, keyed as YYYY-MM.
     *
     * Grouping happens in PHP rather than SQL because the previous
     * DATE_FORMAT() expression was MySQL-only and broke on any other driver.
     * The range is bounded to a single donor over twelve months, so the row
     * count is trivial.
     *
     * @return Collection<string, int>
     */
    public function monthlyCollectedDonationCounts(int $donorId, Carbon $from, Carbon $to): Collection
    {
        return $this->collectedDonations($donorId)
            ->whereBetween('donations.donation_date', [$from, $to])
            ->pluck('donations.donation_date')
            ->groupBy(fn (string $date): string => Carbon::parse($date)->format('Y-m'))
            ->map(fn (Collection $dates): int => $dates->count());
    }

    /**
     * The donor's donations that have a collection row: a bag was drawn.
     *
     * A donation rejected at screening never reaches collection, so it does
     * not count; one rejected afterwards for a reactive result still does,
     * because the donor still gave blood.
     */
    private function collectedDonations(int $donorId): QueryBuilder
    {
        return DB::table('donations')
            ->where('donations.donor_id', $donorId)
            ->whereExists(fn (QueryBuilder $collection) => $collection
                ->select(DB::raw(1))
                ->from('blood_collections')
                ->whereColumn('blood_collections.donation_id', 'donations.id'));
    }
}
