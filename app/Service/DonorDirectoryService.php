<?php

namespace App\Service;

use App\Enums\AccountStatus;
use App\Enums\IdentityStatus;
use App\Enums\RoleName;
use App\Models\Donation;
use App\Models\Facility;
use App\Models\User;
use App\Repository\BloodCenterRepository;
use App\Repository\DonorDirectoryRepository;
use App\Support\AccountIdentity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Donor records as one blood centre is permitted to see them.
 *
 * Two response shapes, decided by whether this facility has dealt with the
 * donor before:
 *
 *  - Own donor: the full record, including this facility's donations in detail.
 *  - Anyone else, found by an exact identifier: identity plus a standardised
 *    donation summary. Counts and dates, never another centre's records.
 *
 * The split is a privacy rule, not a convenience. Detailed records stay with
 * the facility that created them.
 */
class DonorDirectoryService
{
    public function __construct(
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly BloodCenterRepository $bloodCenterRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Page the donors this facility has dealt with.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function browse(User $staff, array $filters, int $perPage): LengthAwarePaginator
    {
        $facility = $this->requireFacility($staff);

        return $this->donorDirectoryRepository
            ->browseForFacility($facility->id, $filters, $perPage)
            ->through(fn (User $donor): array => $this->summarise($donor));
    }

    /**
     * Find one donor by an identifier presented in person.
     *
     * The lookup is audited whichever shape it returns: reading a donor this
     * centre has never met is exactly the access that needs a trail.
     *
     * @return array<string, mixed>
     */
    public function lookup(User $staff, string $type, string $value): array
    {
        $facility = $this->requireFacility($staff);

        $donor = $this->donorDirectoryRepository->findByExactIdentifier($type, $value)
            ?? throw $this->refuse(404, 'donor_not_found', 'No donor matches that identifier.');

        $isOwn = $this->donorDirectoryRepository->facilityHasRelationship($donor->id, $facility->id);

        $this->auditLogger->record($staff, 'donor.looked_up', $donor, [
            'facility_id' => $facility->id,
            'identifier_type' => $type,
            'scope' => $isOwn ? 'own_facility' : 'cross_facility',
        ]);

        return $this->present($donor, $facility, $isOwn);
    }

    /**
     * Show one donor, in whichever shape this facility is entitled to.
     *
     * @return array<string, mixed>
     */
    public function show(User $staff, string $uuid): array
    {
        $facility = $this->requireFacility($staff);

        $donor = $this->donorDirectoryRepository->findDonor($uuid)
            ?? throw $this->refuse(404, 'donor_not_found', 'No donor matches that identifier.');

        $isOwn = $this->donorDirectoryRepository->facilityHasRelationship($donor->id, $facility->id);

        $this->auditLogger->record($staff, 'donor.viewed', $donor, [
            'facility_id' => $facility->id,
            'scope' => $isOwn ? 'own_facility' : 'cross_facility',
        ]);

        return $this->present($donor, $facility, $isOwn);
    }

    /**
     * List this facility's donations for one donor, in detail.
     *
     * Refuses outright rather than returning an empty list when the facility
     * has no relationship with the donor: an empty array would read as "this
     * donor has never donated", which is a different and false claim.
     *
     * @return array<string, mixed>
     */
    public function history(User $staff, string $uuid): array
    {
        $facility = $this->requireFacility($staff);

        $donor = $this->donorDirectoryRepository->findDonor($uuid)
            ?? throw $this->refuse(404, 'donor_not_found', 'No donor matches that identifier.');

        if (! $this->donorDirectoryRepository->facilityHasRelationship($donor->id, $facility->id)) {
            throw $this->refuse(
                403,
                'donor_not_at_facility',
                'Detailed records stay with the facility that created them. Only a summary is available for this donor.'
            );
        }

        $donations = $this->donorDirectoryRepository->donationsAtFacility($donor->id, $facility->id);

        return [
            'donor' => $this->identity($donor),
            'donations' => $donations->map(fn (Donation $donation): array => [
                'id' => $donation->id,
                'donation_date' => $donation->donation_date?->toISOString(),
                'status' => $donation->status?->value,
                'status_label' => $donation->status?->label(),
                'volume_ml' => $donation->volume_ml,
            ])->all(),
        ];
    }

    /**
     * Register a donor who has presented at the counter.
     *
     * A valid ID number is required and unique: it is the identifier a walk-in
     * actually carries, and the thing that stops the same person being
     * registered twice under two spellings of their name. An email address is
     * optional — see the users.email migration for why inventing one is worse
     * than omitting it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function registerWalkIn(User $staff, array $payload): array
    {
        $facility = $this->requireFacility($staff);

        try {
            $donor = DB::transaction(function () use ($payload): User {
                $user = new User;

                $user->fill([
                    'uuid' => (string) Str::uuid(),
                    'first_name' => trim($payload['first_name']),
                    'last_name' => trim($payload['last_name']),
                    'email' => $payload['email'] ?? null,
                    'phone' => $payload['phone'] ?? null,
                    'username' => AccountIdentity::buildUsername(
                        $payload['email'] ?? 'donor-'.Str::lower(Str::random(8))
                    ),
                    // A counter-registered donor has no password they chose.
                    // A random one is set so the column is never empty and the
                    // account cannot be signed into until it is reset.
                    'password' => Hash::make(Str::random(40)),
                    'account_status' => AccountStatus::PendingVerification,
                ]);

                $user->save();

                $this->bloodCenterRepository->attachRole($user, RoleName::Donor->value);

                $user->donorProfile()->create([
                    'donor_id' => $user->id,
                    'blood_type_id' => $payload['blood_type_id'] ?? null,
                    'gender' => $payload['gender'] ?? null,
                    'birth_date' => $payload['birth_date'] ?? null,
                    'address' => isset($payload['address']) ? trim((string) $payload['address']) : null,
                    'valid_id_number' => trim($payload['valid_id_number']),
                ]);

                return $user;
            });
        } catch (QueryException $exception) {
            $this->rethrowUniqueViolation($exception);

            throw $exception;
        }

        // Only if they gave one. sendEmailVerificationNotification() is a no-op
        // for an email-less account, but the intent is clearer stated here.
        if ($donor->hasEmailAddress()) {
            $donor->sendEmailVerificationNotification();
        }

        $this->auditLogger->record($staff, 'donor.registered_at_counter', $donor, [
            'facility_id' => $facility->id,
            'has_email' => $donor->hasEmailAddress(),
        ]);

        return [
            'message' => $donor->first_name.' has been registered.',
            'data' => $this->present($donor->fresh(['donorProfile.bloodType']), $facility, true),
        ];
    }

    /**
     * Build the response for a donor, in the shape this facility may see.
     *
     * @return array<string, mixed>
     */
    private function present(User $donor, Facility $facility, bool $isOwn): array
    {
        $summary = $this->donorDirectoryRepository->donationSummary($donor->id);

        $payload = [
            ...$this->identity($donor),
            'scope' => $isOwn ? 'own_facility' : 'cross_facility',
            'donation_summary' => [
                'total_donations' => $summary['total_donations'],
                'last_donation_at' => $summary['last_donation_at'],
                'next_eligible_date' => $this->nextEligibleDate($summary['last_donation_at']),
            ],
        ];

        if (! $isOwn) {
            // The standardised cross-facility view stops here. No address, no
            // per-donation detail, and nothing naming where they donated.
            $payload['restricted'] = true;
            $payload['restriction_note'] = 'This donor has not donated at your facility. Detailed records stay with the facility that created them.';

            return $payload;
        }

        $profile = $donor->donorProfile;

        return [
            ...$payload,
            'restricted' => false,
            'address' => $profile?->address,
            'gender' => $profile?->gender,
            'valid_id_number' => $profile?->valid_id_number,
            // Status and type only. The document itself is reachable solely
            // through the authenticated route, which blood-centre staff are not
            // authorised for: they match the physical card at the counter.
            'valid_id_type' => $profile?->valid_id_type?->value,
            'identity_status' => ($profile?->identity_status ?? IdentityStatus::Unsubmitted)->value,
            'account_status' => $donor->account_status?->value,
            'email_verified' => $donor->hasVerifiedEmail(),
            'donations_at_this_facility' => $this->donorDirectoryRepository
                ->donationsAtFacility($donor->id, $facility->id)
                ->count(),
        ];
    }

    /**
     * The identity fields shared by both response shapes.
     *
     * Enough for staff holding the donor's ID card to confirm they have the
     * right person, and no more.
     *
     * @return array<string, mixed>
     */
    private function identity(User $donor): array
    {
        $profile = $donor->donorProfile;

        return [
            'uuid' => $donor->uuid,
            'donor_code' => 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT),
            'full_name' => trim($donor->first_name.' '.$donor->last_name),
            'first_name' => $donor->first_name,
            'last_name' => $donor->last_name,
            'blood_type' => $profile?->bloodType?->code,
            'birth_date' => $profile?->birth_date?->toDateString(),
            'phone' => $donor->phone,
            'email' => $donor->email,
        ];
    }

    /**
     * Shape a donor for the browse listing.
     *
     * @return array<string, mixed>
     */
    private function summarise(User $donor): array
    {
        $summary = $this->donorDirectoryRepository->donationSummary($donor->id);

        return [
            ...$this->identity($donor),
            'total_donations' => $summary['total_donations'],
            'last_donation_at' => $summary['last_donation_at'],
            'next_eligible_date' => $this->nextEligibleDate($summary['last_donation_at']),
        ];
    }

    /**
     * The earliest date this donor may give whole blood again.
     *
     * Reads the same config the donor-side eligibility rules use, so the
     * counter and the donor portal cannot disagree about the interval.
     */
    private function nextEligibleDate(?string $lastDonationAt): ?string
    {
        if ($lastDonationAt === null) {
            return null;
        }

        return Carbon::parse($lastDonationAt)
            ->addDays((int) config('donation.interval_days'))
            ->toDateString();
    }

    /**
     * The facility the caller acts for, resolved from the token rather than input.
     */
    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    /**
     * Translate a unique-index collision into a field-level validation error.
     */
    private function rethrowUniqueViolation(QueryException $exception): void
    {
        if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
            return;
        }

        $message = $exception->getMessage();

        $field = match (true) {
            str_contains($message, 'valid_id_number') => 'valid_id_number',
            str_contains($message, 'phone') => 'phone',
            default => 'email',
        };

        throw ValidationException::withMessages([
            $field => ['A donor with this value was just registered. Search for them instead.'],
        ]);
    }

    /**
     * Build the project's refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
