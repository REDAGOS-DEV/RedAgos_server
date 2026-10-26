<?php

namespace App\Service;

use App\Models\Facility;
use App\Models\User;
use App\Repository\DonorDirectoryRepository;
use App\Support\AccountIdentity;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Find a walk-in donor at the Donation counter by name, email or phone.
 *
 * Two reaches, on purpose. A name, or any part of a name, email or phone, is
 * searched only among the donors this centre has already dealt with -- the
 * same list the Donors page shows. Across the whole register a donor is found
 * only by something they could have handed over in person: their exact email,
 * phone, valid ID number or donor code. A partial match across facilities would
 * turn this box into a way to enumerate everyone who has ever given blood
 * (see DonorDirectoryRepository::findByExactIdentifier()).
 *
 * Results identify, they do not profile: enough to tell two Ana Cruzes apart
 * (birth date, masked contact), and the counter then adopts the chosen donor
 * through the exact lookup, which carries the standing-deferral notice.
 */
class CounterDonorSearchService
{
    /** How many matches the counter shows before asking for more of the name. */
    private const LIMIT = 10;

    public function __construct(
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * @return array{data: array<int, array<string, mixed>>, truncated: bool}
     */
    public function search(User $staff, string $term): array
    {
        $facility = $this->requireFacility($staff);
        $term = trim($term);

        $own = $this->donorDirectoryRepository
            ->facilityDonorQuery($facility->id, ['search' => $term, 'sort' => 'name'])
            ->limit(self::LIMIT + 1)
            ->get();

        $truncated = $own->count() > self::LIMIT;
        $results = $own->take(self::LIMIT)
            ->map(fn (User $donor): array => $this->present($donor, 'own_facility', 'search'))
            ->keyBy('uuid');

        foreach ($this->exactMatches($term) as $matchedBy => $donor) {
            if (! $results->has($donor->uuid)) {
                $results->put($donor->uuid, $this->present($donor, 'cross_facility', $matchedBy));
            }
        }

        // Counts only. The term is what a staff member typed about a person,
        // which the audit trail has no need to keep.
        $this->auditLogger->record($staff, 'donor.searched_at_counter', null, [
            'facility_id' => $facility->id,
            'result_count' => $results->count(),
            'cross_facility_count' => $results->where('scope', 'cross_facility')->count(),
        ]);

        return [
            'data' => $results->values()->all(),
            'truncated' => $truncated,
        ];
    }

    /**
     * Donors anywhere in the register whose identifier equals the term exactly.
     *
     * @return array<string, User>
     */
    private function exactMatches(string $term): array
    {
        $matches = [];

        if (str_contains($term, '@')) {
            $matches['email'] = $this->donorDirectoryRepository->findByExactIdentifier('email', $term);
        }

        if (preg_match('/^\+?[\d\s-]{7,}$/', $term) === 1) {
            $matches['phone'] = $this->donorDirectoryRepository
                ->findByExactIdentifier('phone', AccountIdentity::normalizePhilippinePhone($term));
        }

        if (preg_match('/\d/', $term) === 1 && ! str_contains($term, '@')) {
            $matches['donor_code'] = $this->donorDirectoryRepository->findByExactIdentifier('donor_code', $term);
            $matches['valid_id_number'] = $this->donorDirectoryRepository->findByExactIdentifier('valid_id_number', $term);
        }

        return array_filter($matches);
    }

    /**
     * One search result: who this is, and how the counter found them.
     *
     * @return array<string, mixed>
     */
    private function present(User $donor, string $scope, string $matchedBy): array
    {
        $profile = $donor->donorProfile;

        return [
            'uuid' => $donor->uuid,
            'donor_code' => 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT),
            'full_name' => trim($donor->first_name.' '.$donor->last_name),
            'birth_date' => $profile?->birth_date?->toDateString(),
            'blood_type' => $profile?->bloodType?->code,
            'phone_hint' => $this->maskPhone($donor->phone),
            'email_hint' => $this->maskEmail($donor->email),
            'scope' => $scope,
            'matched_by' => $matchedBy,
        ];
    }

    /** +639171234567 → •••• 4567 */
    private function maskPhone(?string $phone): ?string
    {
        if (! filled($phone)) {
            return null;
        }

        return '•••• '.substr((string) $phone, -4);
    }

    /** ana.cruz@gmail.com → a•••@gmail.com */
    private function maskEmail(?string $email): ?string
    {
        if (! filled($email) || ! str_contains((string) $email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', (string) $email, 2);

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility
            ?? throw new HttpResponseException(response()->json([
                'message' => 'This account is not linked to a facility.',
                'code' => 'facility_missing',
            ], 404));
    }
}
