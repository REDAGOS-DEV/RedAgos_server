<?php

namespace App\Service;

use App\Enums\ScreeningOutcome;
use App\Models\DonorDeferral;
use App\Models\Facility;
use App\Models\User;
use App\Repository\DonorDirectoryRepository;
use App\Repository\ManualDeferralRepository;
use App\Support\DonorBlinding;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;

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
 */
class ManualDeferralService
{
    private const WITH = ['donor.donorProfile.bloodType', 'recorder', 'lifter'];

    public function __construct(
        private readonly ManualDeferralRepository $deferrals,
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $staff, array $filters, int $perPage): LengthAwarePaginator
    {
        $facility = $this->requireFacility($staff);

        return $this->deferrals
            ->paginate($facility->id, $filters, $perPage)
            ->through(fn (DonorDeferral $deferral): array => $this->format($deferral, $staff));
    }

    /**
     * Record a deferral on a donor this centre has dealt with.
     *
     * @param  array{donor_uuid: string, outcome: string, reason: string, deferred_on: string, ends_on?: string|null}  $payload
     * @return array<string, mixed>
     */
    public function record(User $staff, array $payload): array
    {
        $facility = $this->requireFacility($staff);
        $donor = $this->ownDonor($facility, $payload['donor_uuid']);

        $deferral = DonorDeferral::create([
            'donor_id' => $donor->id,
            'facility_id' => $facility->id,
            'outcome' => $payload['outcome'],
            'reason' => trim($payload['reason']),
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
     * One row of the Deferrals page.
     *
     * @return array<string, mixed>
     */
    private function format(DonorDeferral $deferral, User $viewer): array
    {
        $status = $deferral->status();

        return [
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
