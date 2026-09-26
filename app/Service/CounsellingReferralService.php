<?php

namespace App\Service;

use App\Enums\ReferralStatus;
use App\Enums\SerologyMarker;
use App\Models\CounsellingReferral;
use App\Models\Facility;
use App\Models\User;
use App\Repository\CounsellingReferralRepository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * The Testing department's follow-up of donors whose serology came back reactive.
 *
 * Section I-C of the DOH form has every donor agree to be referred for
 * counselling if found reactive; this is where the centre keeps that promise.
 * Referrals are opened by LaboratoryService, never here — a referral exists
 * because a reactive reading was recorded, not because someone typed one in.
 *
 * This is the only surface that names which marker a donor was reactive for,
 * which is why it has its own ability (`lab.referrals`) and why every read of
 * the list is audited.
 */
class CounsellingReferralService
{
    public function __construct(
        private readonly CounsellingReferralRepository $counsellingReferralRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Page this facility's referrals.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(User $staff, array $filters, int $perPage): array
    {
        $facility = $this->requireFacility($staff);

        $page = $this->counsellingReferralRepository
            ->paginateForFacility($facility->id, $filters, $perPage)
            ->through(fn (CounsellingReferral $referral): array => $this->format($referral));

        $this->auditLogger->record($staff, 'referral.list_viewed', null, [
            'facility_id' => $facility->id,
            'status' => $filters['status'] ?? 'open',
            'count' => $page->count(),
        ]);

        return [
            ...$page->toArray(),
            'pending_count' => $this->counsellingReferralRepository->pendingCount($facility->id),
        ];
    }

    /**
     * Move a referral on, and record who did and when.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(User $staff, int $referralId, array $payload): array
    {
        $facility = $this->requireFacility($staff);
        $target = ReferralStatus::from($payload['status']);

        $from = DB::transaction(function () use ($staff, $facility, $referralId, $payload, $target): ReferralStatus {
            $locked = $this->counsellingReferralRepository->lockReferral($referralId, $facility->id)
                ?? throw $this->refuse(404, 'referral_not_found', 'That referral was not found at your facility.');

            $from = $locked->status;

            if ($from === ReferralStatus::Closed) {
                throw $this->refuse(409, 'referral_closed', 'This referral is closed and can no longer be changed.');
            }

            if (! $from->canMoveTo($target)) {
                throw $this->refuse(
                    409,
                    'referral_transition_invalid',
                    "A referral that is {$from->label()} cannot be marked {$target->label()}."
                );
            }

            $locked->status = $target;
            $locked->updated_by = $staff->id;

            if ($column = $target->timestampColumn()) {
                $locked->setAttribute($column, now());
            }

            if (array_key_exists('note', $payload)) {
                $note = is_string($payload['note']) ? trim($payload['note']) : null;
                $locked->note = $note === '' ? null : $note;
            }

            $locked->save();

            return $from;
        });

        $referral = $this->counsellingReferralRepository->findReferral($referralId, $facility->id);

        $this->auditLogger->record($staff, 'referral.status_changed', $referral, [
            'facility_id' => $facility->id,
            'from' => $from->value,
            'to' => $target->value,
        ]);

        return [
            'message' => 'Referral marked '.$target->label().'.',
            'data' => $this->format($referral),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function format(CounsellingReferral $referral): array
    {
        $donor = $referral->donorProfile?->donor;
        $profile = $referral->donorProfile;
        $donation = $referral->donation;
        $serology = $donation?->serology;

        return [
            'id' => $referral->id,
            'status' => $referral->status->value,
            'status_label' => $referral->status->label(),
            'is_open' => $referral->status->isOpen(),
            'flagged_at' => $referral->created_at?->toISOString(),
            'contacted_at' => $referral->contacted_at?->toISOString(),
            'referred_at' => $referral->referred_at?->toISOString(),
            'closed_at' => $referral->closed_at?->toISOString(),
            'note' => $referral->note,
            'updated_by' => $referral->updater
                ? trim($referral->updater->first_name.' '.$referral->updater->last_name)
                : null,
            'donor' => $donor === null ? null : [
                'uuid' => $donor->uuid,
                'donor_code' => 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT),
                'full_name' => trim($donor->first_name.' '.$donor->last_name),
                'phone' => $donor->phone,
                'email' => $donor->hasEmailAddress() ? $donor->email : null,
                // Section I-C's contact person, for a donor who cannot be reached.
                'contact_person' => $profile?->contact_person_name,
                'contact_person_number' => $profile?->contact_person_number,
            ],
            'donation' => $donation === null ? null : [
                'id' => $donation->id,
                'donation_date' => $donation->donation_date?->toISOString(),
                'segment_number' => $donation->collection?->segment_number,
            ],
            'reactive_markers' => $serology === null ? [] : array_map(
                fn (SerologyMarker $marker): array => ['value' => $marker->value, 'label' => $marker->label()],
                $serology->reactiveMarkers()
            ),
            'screened_by' => $serology?->recorder
                ? trim($serology->recorder->first_name.' '.$serology->recorder->last_name)
                : null,
            'screened_at' => $serology?->recorded_at?->toISOString(),
        ];
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
