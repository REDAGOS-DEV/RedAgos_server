<?php

namespace App\Service;

use App\Enums\IdentityStatus;
use App\Models\DonorProfile;
use App\Models\User;
use App\Notifications\DonorIdentityDecision;
use App\Repository\DonorRepository;
use App\Support\AccountIdentity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
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
    /**
     * Why another account may be the same person, strongest first. See
     * likeness().
     */
    private const LIKENESS = [
        'name_and_birth_date' => 'Same name and date of birth',
        'last_name_and_birth_date' => 'Same last name and date of birth',
        'first_name_and_birth_date' => 'Same first name and date of birth',
    ];

    public function __construct(
        private readonly DonorRepository $donorRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * List donors whose identity document is in a given state, each with the
     * other accounts that may be the same person.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(IdentityStatus $status, int $perPage): LengthAwarePaginator
    {
        $page = $this->donorRepository->identitySubmissionsByStatus($status, $perPage);
        $context = $this->contextFor($page->getCollection());

        return $page->through(fn (User $donor): array => $this->format($donor, $context));
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
            'donor' => $this->formatOne($donor->refresh()),
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
            'donor' => $this->formatOne($donor->refresh()),
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
     * What the queue shows beside these donors' own details: the accounts that
     * may be the same person, where every account involved was created, and
     * how often each has given blood. Five queries for a whole page.
     *
     * @param  EloquentCollection<int, User>  $donors
     * @return array{lookalikes: EloquentCollection<int, User>, registered_at: Collection<int, string>, donations: Collection<int, array{total: int, last_at: ?string}>}
     */
    private function contextFor(EloquentCollection $donors): array
    {
        $lookalikes = $this->donorRepository->lookalikeDonors($donors);
        $ids = array_values(array_unique([...$donors->modelKeys(), ...$lookalikes->modelKeys()]));

        return [
            'lookalikes' => $lookalikes,
            'registered_at' => $this->donorRepository->counterRegistrations($ids),
            'donations' => $this->donorRepository->collectedDonationSummaries($lookalikes->modelKeys()),
        ];
    }

    /**
     * Why another account may be the same person as this donor (a LIKENESS
     * key), or null when it is not. Always the same birthday; see
     * DonorRepository::lookalikeDonors().
     */
    private function likeness(User $donor, User $other): ?string
    {
        $born = $donor->donorProfile?->birth_date?->toDateString();

        if ($born === null || $born !== $other->donorProfile?->birth_date?->toDateString()) {
            return null;
        }

        $sameFirst = AccountIdentity::comparableName($donor->first_name) === AccountIdentity::comparableName($other->first_name);
        $sameLast = AccountIdentity::comparableName($donor->last_name) === AccountIdentity::comparableName($other->last_name);

        return match (true) {
            $sameFirst && $sameLast => 'name_and_birth_date',
            $sameLast => 'last_name_and_birth_date',
            $sameFirst => 'first_name_and_birth_date',
            default => null,
        };
    }

    /**
     * The other accounts that may be this donor, strongest likeness first.
     *
     * @param  array{lookalikes: EloquentCollection<int, User>, registered_at: Collection<int, string>, donations: Collection<int, array{total: int, last_at: ?string}>}  $context
     * @return array<int, array<string, mixed>>
     */
    private function possibleMatches(User $donor, array $context): array
    {
        $rank = array_flip(array_keys(self::LIKENESS));

        return $context['lookalikes']
            ->reject(fn (User $other): bool => $other->is($donor))
            ->map(fn (User $other): array => ['donor' => $other, 'likeness' => $this->likeness($donor, $other)])
            ->filter(fn (array $match): bool => $match['likeness'] !== null)
            ->sortBy(fn (array $match): int => $rank[$match['likeness']])
            ->map(function (array $match) use ($context): array {
                $other = $match['donor'];
                $profile = $other->donorProfile;
                $donations = $context['donations']->get($other->id);

                return [
                    'uuid' => $other->uuid,
                    'donor_code' => AccountIdentity::donorCode($other->id),
                    'full_name' => $this->fullName($other),
                    'likeness' => $match['likeness'],
                    'likeness_label' => self::LIKENESS[$match['likeness']],
                    'email' => $other->email,
                    'phone' => $other->phone,
                    'birth_date' => $profile?->birth_date?->toDateString(),
                    'created_at' => $other->created_at?->toIso8601String(),
                    'registered_at' => $context['registered_at']->get($other->id),
                    'account_status' => $other->account_status?->value,
                    'identity_status' => ($profile?->identity_status ?? IdentityStatus::Unsubmitted)->value,
                    'valid_id_type' => $profile?->valid_id_type?->value,
                    'valid_id_type_label' => $profile?->valid_id_type?->label(),
                    'valid_id_number_masked' => AccountIdentity::maskValidIdNumber($profile?->valid_id_number),
                    'donations' => $donations['total'] ?? 0,
                    'last_donation_at' => $donations['last_at'] ?? null,
                    'image_url' => $this->imageUrl($other),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Present one donor outside a page of the queue, with the same context.
     *
     * @return array<string, mixed>
     */
    private function formatOne(User $donor): array
    {
        return $this->format($donor, $this->contextFor(new EloquentCollection([$donor])));
    }

    /**
     * Present one submission for the review queue.
     *
     * The number is masked: the administrator reads it off the document image,
     * and a queue that carried every ID in full would make a bulk export of the
     * queue a bulk export of ID numbers.
     *
     * possible_matches is the queue's real question: whether this person
     * already holds another account (project owner, 2026-11-03).
     *
     * @param  array{lookalikes: EloquentCollection<int, User>, registered_at: Collection<int, string>, donations: Collection<int, array{total: int, last_at: ?string}>}  $context
     * @return array<string, mixed>
     */
    private function format(User $donor, array $context): array
    {
        $profile = $donor->donorProfile;
        $status = $profile?->identity_status ?? IdentityStatus::Unsubmitted;

        return [
            'uuid' => $donor->uuid,
            'donor_code' => AccountIdentity::donorCode($donor->id),
            'full_name' => $this->fullName($donor),
            'email' => $donor->email,
            'phone' => $donor->phone,
            'birth_date' => $profile?->birth_date?->toDateString(),
            'blood_type' => $profile?->bloodType?->code,
            'address' => $profile?->address,
            'created_at' => $donor->created_at?->toIso8601String(),
            'registered_at' => $context['registered_at']->get($donor->id),
            'identity_status' => $status->value,
            'valid_id_type' => $profile?->valid_id_type?->value,
            'valid_id_type_label' => $profile?->valid_id_type?->label(),
            'valid_id_number_masked' => AccountIdentity::maskValidIdNumber($profile?->valid_id_number),
            'submission_version' => (int) ($profile?->identity_submission_version ?? 0),
            'submitted_at' => $profile?->identity_submitted_at?->toIso8601String(),
            'reviewed_at' => $profile?->identity_reviewed_at?->toIso8601String(),
            'reviewed_by' => $profile?->identityReviewer?->first_name,
            'rejection_reason' => $profile?->identity_rejection_reason,
            'image_url' => $this->imageUrl($donor),
            'possible_matches' => $this->possibleMatches($donor, $context),
        ];
    }

    /**
     * First, middle and last name. The middle name is what tells two people
     * of the same first and last name apart.
     */
    private function fullName(User $donor): string
    {
        return trim(implode(' ', array_filter(
            [$donor->first_name, $donor->middle_name, $donor->last_name],
            fn (?string $part): bool => filled($part)
        )));
    }

    private function imageUrl(User $donor): ?string
    {
        return $donor->donorProfile?->valid_id_image_path
            ? '/donors/'.$donor->uuid.'/identity-image'
            : null;
    }
}
