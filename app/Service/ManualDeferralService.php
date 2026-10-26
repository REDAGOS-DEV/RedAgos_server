<?php

namespace App\Service;

use App\Enums\ScreeningOutcome;
use App\Models\DonationScreening;
use App\Models\DonorDeferral;
use App\Models\Facility;
use App\Models\User;
use App\Repository\DonorDirectoryRepository;
use App\Repository\ManualDeferralRepository;
use App\Repository\ScreeningDeferralRepository;
use App\Support\DonorBlinding;
use App\Support\OperationalDay;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * The Deferrals page (project owner, 2026-10-18): deferrals the screening
 * physician records on a donor by hand, outside any visit, and lifts later.
 *
 * Recording one is all it takes for every other screen to hear of it: the
 * Donors list, the counter's prior-deferral notice, the desk's booking
 * refusal and the eligibility reminders all read DonorDeferralRepository,
 * where these are the third source.
 *
 * Each centre lists and lifts only what it recorded — detailed records stay
 * with the facility that created them. Another centre still learns the donor
 * is deferred, with the outcome and date and never the reason, through the
 * standing deferral.
 *
 * The list also carries the centre's screening deferrals, from the counter
 * and from a drive's paper records (project owner, 2026-10-25), marked by
 * source, and lifted here the same way; the screening keeps its outcome as
 * the record of that visit. A laboratory deferral stays off it; the counter
 * staff who read this page are not told a result.
 */
class ManualDeferralService
{
    private const WITH = ['donor.donorProfile.bloodType', 'recorder', 'lifter'];

    public function __construct(
        private readonly ManualDeferralRepository $deferrals,
        private readonly ScreeningDeferralRepository $screeningDeferrals,
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly DeferralCodeService $deferralCodes,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * The centre's deferrals, newest first: those recorded here, and its
     * screening deferrals (counter and paper).
     *
     * Two tables, so each gives the ids, days and times of its matches; they
     * are merged and sorted, and only the page shown is loaded.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $staff, array $filters, int $perPage): LengthAwarePaginator
    {
        $facility = $this->requireFacility($staff);

        $keys = [
            ...$this->deferrals->keys($facility->id, $filters),
            ...$this->screeningDeferrals->keys($facility->id, $filters),
        ];

        usort($keys, fn (array $a, array $b): int => [$b['date'], $b['at'], $b['id']] <=> [$a['date'], $a['at'], $a['id']]);

        $page = Paginator::resolveCurrentPage();
        $slice = array_slice($keys, ($page - 1) * $perPage, $perPage);

        $manual = $this->deferrals
            ->findMany($facility->id, $this->idsOf($slice, 'manual'))
            ->keyBy('id');
        $screenings = $this->screeningDeferrals
            ->findMany($facility->id, $this->idsOf($slice, 'screening'))
            ->keyBy('id');

        $rows = array_values(array_filter(array_map(
            fn (array $key): ?array => $key['source'] === 'manual'
                ? (($deferral = $manual->get($key['id'])) ? $this->format($deferral, $staff) : null)
                : (($screening = $screenings->get($key['id'])) ? $this->formatScreening($screening, $staff) : null),
            $slice
        )));

        return new Paginator($rows, count($keys), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /**
     * @param  array<int, array{source: string, id: int}>  $keys
     * @return array<int, int>
     */
    private function idsOf(array $keys, string $source): array
    {
        return array_values(array_map(
            fn (array $key): int => $key['id'],
            array_filter($keys, fn (array $key): bool => $key['source'] === $source)
        ));
    }

    /**
     * Record a deferral on a donor this centre has dealt with, under one of
     * the centre's deferral codes (its reason copied as it reads today), or
     * under "Other" with the reason typed and no code.
     *
     * @param  array{donor_uuid: string, outcome: string, deferral_code?: string|null, other_reason?: string|null, deferred_on: string, ends_on?: string|null}  $payload
     * @return array<string, mixed>
     */
    public function record(User $staff, array $payload): array
    {
        $facility = $this->requireFacility($staff);
        $donor = $this->ownDonor($facility, $payload['donor_uuid']);
        $chosen = $this->deferralCodes->chosen($facility, $payload);

        $deferral = DonorDeferral::create([
            'donor_id' => $donor->id,
            'facility_id' => $facility->id,
            'outcome' => $payload['outcome'],
            'reason_code' => $chosen['code'],
            'reason' => $chosen['reason'],
            'deferred_on' => $payload['deferred_on'],
            'ends_on' => $payload['outcome'] === ScreeningOutcome::TemporarilyDeferred->value
                ? ($payload['ends_on'] ?? null)
                : null,
            'recorded_by' => $staff->id,
        ]);

        // The outcome, never the reason: the audit trail must not copy clinical text.
        $this->auditLogger->record($staff, 'donor.deferred', $donor, [
            'facility_id' => $facility->id,
            'deferral_id' => $deferral->id,
            'outcome' => $deferral->outcome->value,
        ]);

        return $this->format($deferral->load(self::WITH), $staff);
    }

    /**
     * Lift a deferral this centre recorded, keeping the row and saying why.
     *
     * @return array<string, mixed>
     */
    public function lift(User $staff, int $deferralId, string $reason): array
    {
        $facility = $this->requireFacility($staff);

        $deferral = $this->deferrals->find($facility->id, $deferralId)
            ?? throw $this->refuse(404, 'deferral_not_found', 'This centre recorded no such deferral.');

        if ($deferral->status() === DonorDeferral::LIFTED) {
            throw $this->refuse(422, 'deferral_already_lifted', 'This deferral has already been lifted.');
        }

        if ($deferral->status() === DonorDeferral::EXPIRED) {
            throw $this->refuse(422, 'deferral_expired', 'This deferral has already ended; there is nothing to lift.');
        }

        $deferral->update([
            'lifted_at' => now(),
            'lifted_by' => $staff->id,
            'lift_reason' => trim($reason),
        ]);

        $this->auditLogger->record($staff, 'donor.deferral_lifted', $deferral->donor, [
            'facility_id' => $facility->id,
            'deferral_id' => $deferral->id,
        ]);

        return $this->format($deferral->refresh()->load(self::WITH), $staff);
    }

    /**
     * Lift a screening deferral this centre recorded (the counter's, or a
     * drive paper's), as one recorded on the page is lifted. The screening
     * keeps its outcome; the deferral stops counting.
     *
     * @return array<string, mixed>
     */
    public function liftScreening(User $staff, int $screeningId, string $reason): array
    {
        $facility = $this->requireFacility($staff);

        $screening = $this->screeningDeferrals->find($facility->id, $screeningId)
            ?? throw $this->refuse(404, 'deferral_not_found', 'This centre recorded no such deferral.');

        $status = $this->screeningStatus($screening);

        if ($status === DonorDeferral::LIFTED) {
            throw $this->refuse(422, 'deferral_already_lifted', 'This deferral has already been lifted.');
        }

        if ($status === DonorDeferral::EXPIRED) {
            throw $this->refuse(422, 'deferral_expired', 'This deferral has already ended; there is nothing to lift.');
        }

        $screening->update([
            'lifted_at' => now(),
            'lifted_by' => $staff->id,
            'lift_reason' => trim($reason),
        ]);

        $this->auditLogger->record($staff, 'donor.deferral_lifted', $screening->donation?->donorProfile?->donor, [
            'facility_id' => $facility->id,
            'donation_id' => $screening->donation_id,
            'source' => 'screening',
        ]);

        return $this->formatScreening($screening->refresh()->load(['donation.donorProfile.donor', 'donation.donorProfile.bloodType', 'recorder', 'lifter']), $staff);
    }

    /**
     * active, expired or lifted, as the donor's standing reads a screening
     * deferral (ScreeningDeferralRepository).
     */
    private function screeningStatus(DonationScreening $screening): string
    {
        return match (true) {
            $screening->lifted_at !== null => DonorDeferral::LIFTED,
            $screening->outcome !== ScreeningOutcome::TemporarilyDeferred => DonorDeferral::ACTIVE,
            $screening->deferred_until !== null => $screening->deferred_until->toDateString() >= OperationalDay::todayAsDate()
                ? DonorDeferral::ACTIVE
                : DonorDeferral::EXPIRED,
            default => $this->screeningDeferrals->supersededLater($screening) ? DonorDeferral::EXPIRED : DonorDeferral::ACTIVE,
        };
    }

    /**
     * One row of the Deferrals page.
     *
     * @return array<string, mixed>
     */
    private function format(DonorDeferral $deferral, User $viewer): array
    {
        $status = $deferral->status();

        return [
            'key' => "manual-{$deferral->id}",
            'source' => 'manual',
            'id' => $deferral->id,
            'code' => 'DEF-'.str_pad((string) $deferral->id, 6, '0', STR_PAD_LEFT),
            'donor' => DonorBlinding::block(
                $deferral->donor,
                $deferral->donor?->donorProfile?->bloodType?->code,
                $viewer
            ),
            'outcome' => $deferral->outcome->value,
            'outcome_label' => $deferral->outcome->label(),
            'kind' => match ($deferral->outcome) {
                ScreeningOutcome::PermanentlyDeferred => 'permanent',
                ScreeningOutcome::IndefiniteDeferral => 'indefinite',
                default => 'temporary',
            },
            // The list sits behind donors.view_clinical, so it carries the
            // reason; the page shows the code and reveals the reason on request.
            'reason_code' => $deferral->reason_code,
            'reason' => $deferral->reason,
            'deferred_on' => $deferral->deferred_on?->toDateString(),
            'ends_on' => $deferral->ends_on?->toDateString(),
            'status' => $status,
            'recorded_by' => $this->nameOf($deferral->recorder),
            'recorded_at' => $deferral->created_at?->toISOString(),
            'lifted_at' => $deferral->lifted_at?->toISOString(),
            'lifted_by' => $this->nameOf($deferral->lifter),
            'lift_reason' => $deferral->lift_reason,
            'can' => [
                'lift' => $status === DonorDeferral::ACTIVE && $viewer->can('donors.defer'),
            ],
        ];
    }

    /**
     * One row of the Deferrals page for a screening deferral: the visit's
     * code, and the counter or the paper as its source.
     *
     * @return array<string, mixed>
     */
    private function formatScreening(DonationScreening $screening, User $viewer): array
    {
        $donation = $screening->donation;
        $status = $this->screeningStatus($screening);

        return [
            'key' => "screening-{$screening->id}",
            'source' => $donation?->paper_sheet_id ? 'paper' : 'counter',
            'id' => $screening->id,
            'code' => 'DON-'.str_pad((string) $donation?->id, 6, '0', STR_PAD_LEFT),
            'donation_id' => $donation?->id,
            'donor' => DonorBlinding::block(
                $donation?->donorProfile?->donor,
                $donation?->donorProfile?->bloodType?->code,
                $viewer
            ),
            'outcome' => $screening->outcome->value,
            'outcome_label' => $screening->outcome->label(),
            'kind' => match ($screening->outcome) {
                ScreeningOutcome::PermanentlyDeferred => 'permanent',
                ScreeningOutcome::IndefiniteDeferral => 'indefinite',
                default => 'temporary',
            },
            'reason_code' => $screening->deferral_code,
            'reason' => $screening->deferral_reason,
            'deferred_on' => $screening->screened_at ? OperationalDay::dateOf($screening->screened_at) : null,
            'ends_on' => $screening->deferred_until?->toDateString(),
            'status' => $status,
            'recorded_by' => $screening->screenerName(),
            'recorded_at' => $screening->screened_at?->toISOString(),
            'lifted_at' => $screening->lifted_at?->toISOString(),
            'lifted_by' => $this->nameOf($screening->lifter),
            'lift_reason' => $screening->lift_reason,
            'can' => [
                'lift' => $status === DonorDeferral::ACTIVE && $viewer->can('donors.defer'),
            ],
        ];
    }

    private function nameOf(?User $user): ?string
    {
        return $user === null ? null : $user->signatureName();
    }

    /**
     * A donor this centre has dealt with, or a refusal.
     */
    private function ownDonor(Facility $facility, string $uuid): User
    {
        $donor = $this->donorDirectoryRepository->findDonor($uuid)
            ?? throw $this->refuse(404, 'donor_not_found', 'No donor matches that identifier.');

        if (! $this->donorDirectoryRepository->facilityHasRelationship($donor->id, $facility->id)) {
            throw $this->refuse(
                403,
                'donor_not_at_facility',
                'Only a centre this donor has visited or registered with can defer them.'
            );
        }

        return $donor;
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

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
