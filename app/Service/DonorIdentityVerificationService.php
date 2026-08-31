<?php

namespace App\Service;

use App\Enums\IdentityStatus;
use App\Models\DonorProfile;
use App\Models\User;
use App\Notifications\DonorIdentityDecision;
use App\Repository\DonorRepository;
use App\Support\AccountIdentity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Administrator review of donor identity documents.
 *
 * Mirrors FacilityApprovalService: the decision is taken against a locked row so
 * concurrent reviews serialise, the audit trail carries identifiers only, and
 * the donor is notified after the commit.
 */
class DonorIdentityVerificationService
{
    public function __construct(
        private readonly DonorRepository $donorRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * List donors whose identity document is in a given state.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(IdentityStatus $status, int $perPage): LengthAwarePaginator
    {
        return $this->donorRepository
            ->identitySubmissionsByStatus($status, $perPage)
            ->through(fn (User $donor): array => $this->format($donor));
    }

    /**
     * Approve a donor's identity document.
     *
     * @return array<string, mixed>
     */
    public function approve(User $admin, string $uuid, int $submissionVersion): array
    {
        $donor = $this->findDonor($uuid);

        $updated = DB::transaction(function () use ($admin, $donor, $submissionVersion): DonorProfile {
            $locked = $this->lockOrFail($donor);

            $this->guardPending($locked);
            $this->guardSubmissionVersion($locked, $submissionVersion);

            $locked->identity_status = IdentityStatus::Verified;
            $locked->identity_reviewed_at = now();
            $locked->identity_reviewed_by = $admin->id;
            $locked->identity_rejection_reason = null;
            $locked->save();

            return $locked;
        });

        $this->auditLogger->record($admin, 'donor.identity_verified', $updated, [
            'donor_id' => $donor->id,
            'submission_version' => $updated->identity_submission_version,
        ]);

        $donor->notify(new DonorIdentityDecision(IdentityStatus::Verified));

        return [
            'message' => $donor->first_name."'s ID has been verified.",
            'donor' => $this->format($donor->refresh()),
        ];
    }

    /**
     * Reject a donor's identity document, recording why so they can resubmit.
     *
     * @return array<string, mixed>
     */
    public function reject(User $admin, string $uuid, int $submissionVersion, string $reason): array
    {
        $donor = $this->findDonor($uuid);

        $updated = DB::transaction(function () use ($admin, $donor, $submissionVersion, $reason): DonorProfile {
            $locked = $this->lockOrFail($donor);

            $this->guardPending($locked);
            $this->guardSubmissionVersion($locked, $submissionVersion);

            $locked->identity_status = IdentityStatus::Rejected;
            $locked->identity_reviewed_at = now();
            $locked->identity_reviewed_by = $admin->id;
            $locked->identity_rejection_reason = $reason;
            $locked->save();

            return $locked;
        });

        $this->auditLogger->record($admin, 'donor.identity_rejected', $updated, [
            'donor_id' => $donor->id,
            'submission_version' => $updated->identity_submission_version,
        ]);

        $donor->notify(new DonorIdentityDecision(IdentityStatus::Rejected, $reason));

        return [
            'message' => $donor->first_name."'s ID was not approved.",
            'donor' => $this->format($donor->refresh()),
        ];
    }

    /**
     * Resolve the donor a decision is being taken on.
     */
    private function findDonor(string $uuid): User
    {
        $donor = $this->donorRepository->findDonorByUuid($uuid);

        if (! $donor) {
            throw new HttpResponseException(response()->json([
                'message' => 'This donor no longer exists.',
                'code' => 'donor_missing',
            ], 404));
        }

        return $donor;
    }

    /**
     * Re-read the profile under a row lock so concurrent decisions serialise.
     */
    private function lockOrFail(User $donor): DonorProfile
    {
        $locked = DonorProfile::whereKey($donor->id)->lockForUpdate()->first();

        if (! $locked) {
            throw new HttpResponseException(response()->json([
                'message' => 'This donor no longer has a profile.',
                'code' => 'donor_profile_missing',
            ], 404));
        }

        return $locked;
    }

    /**
     * Refuse a decision on a document that is not awaiting review.
     *
     * Checked against the locked row, so the loser of a race sees the winner's
     * result rather than writing over it.
     */
    private function guardPending(DonorProfile $profile): void
    {
        $status = $profile->identity_status ?? IdentityStatus::Unsubmitted;

        if ($status->awaitsReview()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'This ID is not awaiting review.',
            'code' => 'identity_not_pending',
        ], 409));
    }

    /**
     * Refuse a decision taken on a document the donor has since replaced.
     *
     * The version rather than the submitted-at timestamp: two submissions can
     * land inside the same second, and a stale approval would then compare equal
     * and let through a document nobody reviewed.
     */
    private function guardSubmissionVersion(DonorProfile $profile, int $submissionVersion): void
    {
        if ((int) $profile->identity_submission_version === $submissionVersion) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'This submission was replaced. Reload the queue and review the current document.',
            'code' => 'identity_submission_stale',
            'current_submission_version' => (int) $profile->identity_submission_version,
        ], 409));
    }

    /**
     * Present one submission for the review queue.
     *
     * The number is masked: the administrator reads it off the document image,
     * and a queue that carried every ID in full would make a bulk export of the
     * queue a bulk export of ID numbers.
     *
     * @return array<string, mixed>
     */
    private function format(User $donor): array
    {
        $profile = $donor->donorProfile;
        $status = $profile?->identity_status ?? IdentityStatus::Unsubmitted;

        return [
            'uuid' => $donor->uuid,
            'full_name' => trim($donor->first_name.' '.$donor->last_name),
            'email' => $donor->email,
            'phone' => $donor->phone,
            'birth_date' => $profile?->birth_date?->toDateString(),
            'blood_type' => $profile?->bloodType?->code,
            'address' => $profile?->address,
            'identity_status' => $status->value,
            'valid_id_type' => $profile?->valid_id_type?->value,
            'valid_id_type_label' => $profile?->valid_id_type?->label(),
            'valid_id_number_masked' => AccountIdentity::maskValidIdNumber($profile?->valid_id_number),
            'submission_version' => (int) ($profile?->identity_submission_version ?? 0),
            'submitted_at' => $profile?->identity_submitted_at?->toIso8601String(),
            'reviewed_at' => $profile?->identity_reviewed_at?->toIso8601String(),
            'reviewed_by' => $profile?->identityReviewer?->first_name,
            'rejection_reason' => $profile?->identity_rejection_reason,
            'image_url' => $profile?->valid_id_image_path
                ? '/donors/'.$donor->uuid.'/identity-image'
                : null,
        ];
    }
}
