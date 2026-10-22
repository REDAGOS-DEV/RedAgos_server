<?php

namespace App\Service;

use App\Enums\ClearanceKind;
use App\Enums\DonationStatus;
use App\Enums\MarkerResult;
use App\Enums\SerologyMarker;
use App\Enums\StaffRole;
use App\Enums\TestResult;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\DonationSerology;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\SerologyVerificationRequested;
use App\Repository\ClearanceRepository;
use App\Repository\LaboratoryRepository;
use App\Support\BagNumbers;
use App\Support\DepartmentPermissions;
use App\Support\DonorBlinding;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Testing and Processing: what was found, what it yielded, and whether it may be issued.
 *
 * These two departments own a donation from `collected` to `completed`. It is the
 * only place `completed` can be written, and `completed` is what blood-unit
 * intake gates on — so the rules here are the last thing between an untested
 * bag and a patient.
 *
 * TESTING records the two sections of Section II of the DOH form it owns:
 * immunohematology (ABO/Rh) and the five-marker serology panel. Each is saved
 * separately and stamped with whoever saved it — the form's "Screened by".
 *
 * RedAgos does not perform the assay. A qualified professional does; this
 * records what they reported. The one thing computed here is the roll-up the
 * form itself implies: any reactive marker makes the donation reactive, and
 * all five non-reactive with a typing makes it pass. No reading is inferred.
 */
class LaboratoryService
{
    /**
     * The reason written on a donation rejected for a reactive marker.
     *
     * Fixed, and deliberately silent about which marker: the Collection
     * department reads rejection reasons in a donor's history, and which
     * infection a donor carries is shown only on the Testing department's
     * referral list.
     */
    public const REACTIVE_REJECTION_REASON = 'Not suitable for issue: laboratory screening.';

    public function __construct(
        private readonly LaboratoryRepository $laboratoryRepository,
        private readonly AuditLogger $auditLogger,
        private readonly ClearanceRepository $clearanceRepository
    ) {}

    /**
     * Page the donations awaiting testing or processing at this facility.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function queue(User $staff, array $filters, int $perPage): LengthAwarePaginator
    {
        $facility = $this->requireFacility($staff);

        return $this->laboratoryRepository
            ->paginateQueue($facility->id, $filters, $perPage)
            ->through(fn (Donation $donation): array => $this->format($donation, $staff));
    }

    /**
     * Show one donation with everything the laboratory has recorded against it.
     *
     * @return array<string, mixed>
     */
    public function show(User $staff, int $donationId): array
    {
        $facility = $this->requireFacility($staff);

        return $this->format($this->findOrFail($donationId, $facility), $staff);
    }

    /**
     * Record the confirmatory ABO/Rh typing a medical technologist reported.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recordImmunohematology(User $staff, int $donationId, array $payload, bool $correcting = false): array
    {
        $facility = $this->requireFacility($staff);
        $bloodTypeId = (int) $payload['blood_type_id'];

        [$donation, $amended, $settled] = DB::transaction(function () use ($staff, $facility, $donationId, $payload, $bloodTypeId, $correcting): array {
            $locked = $this->lockOrFail($donationId, $facility);

            $this->guardResultsWritable($locked, ClearanceKind::Immunohematology);
            $this->guardBloodTypeMatchesDonor($locked, $bloodTypeId);

            $amended = $this->laboratoryRepository->immunohematologyFor($locked->id) !== null;

            // Saved once, changed only through an approved correction request.
            if (! $correcting && $amended) {
                throw $this->refuse(
                    409,
                    'correction_required',
                    'The typing is already recorded. Request a correction to change it.'
                );
            }

            $typing = $this->laboratoryRepository->upsertImmunohematology($locked->id, [
                'blood_type_id' => $bloodTypeId,
                'forward_group' => $payload['forward_group'],
                // Optional: not asked for on the Testing page.
                'reverse_group' => $payload['reverse_group'] ?? null,
                'antibody_screen' => $payload['antibody_screen'] ?? null,
                'notes' => $this->trimmedOrNull($payload['notes'] ?? null),
                // "Screened by": the authenticated staff member, never a name
                // from the request.
                'recorded_by' => $staff->id,
                'recorded_at' => now(),
            ]);

            // Immunohematology's clearance, issued the moment the typing can
            // be trusted: the forward read is recorded, and any reverse group
            // or antibody screen sent with it agrees and is negative.
            // Otherwise the typing is saved, held, and may be corrected. A cleared typing is also what the donor's profile
            // may adopt — so a first-time donor's bags can be booked into
            // quarantine before the serology run is back.
            $hold = $typing->clearanceHold();
            $adopted = false;

            if ($hold === null) {
                $this->clearanceRepository->issue($locked->id, ClearanceKind::Immunohematology, $staff, 'concordant_typing');
                $adopted = $this->adoptVerifiedBloodType($locked, $bloodTypeId, TestResult::Passed);
            }

            $settled = $this->settle($locked, $staff);

            return [$locked, $amended, [...$settled, 'adopted' => $adopted, 'hold' => $hold]];
        });

        $this->auditLogger->record($staff, 'laboratory.immunohematology_recorded', $donation, [
            'facility_id' => $facility->id,
            'blood_type_id' => $bloodTypeId,
            'amended' => $amended,
            'cleared' => $settled['hold'] === null,
            'hold' => $settled['hold'],
            'result' => $settled['result'],
        ]);

        $this->recordAdoption($staff, $donation, $facility, $settled);

        return [
            'message' => match ($settled['hold']) {
                'abo_discrepancy' => 'Typing saved but not cleared: forward and reverse grouping disagree. Resolve the discrepancy and correct the record.',
                'antibody_screen_positive' => 'Typing saved but not cleared: the antibody screen is positive and must be identified first.',
                default => $settled['result'] === TestResult::Passed->value
                    ? 'Typing cleared. Both sections are cleared; its units may leave quarantine.'
                    : 'Typing cleared.',
            },
            'clearance_hold' => $settled['hold'],
            'data' => $this->format($this->findOrFail($donationId, $facility), $staff),
        ];
    }

    /**
     * Record the five-marker serology panel a medical technologist reported.
     *
     * A verified reactive marker is final and sets off everything the donor
     * agreed to in Section I-C, in the same transaction: the donation is
     * rejected, the donor is permanently deferred and referred for
     * counselling. The donor is asked to get in touch by hand, from the
     * referral list (CounsellingReferralService::requestContact()).
     *
     * A reactive panel is verified as it is saved only by the Laboratory
     * Supervisor (lab.verify_serology), or by a correction's approval, whose
     * approver is passed as $verifier. Saved by anyone else it waits, having
     * set nothing off, for verifySerology() or returnSerology() (project
     * owner, 2026-10-18): one mis-click must not defer a donor at every centre.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recordSerology(User $staff, int $donationId, array $payload, bool $correcting = false, ?User $verifier = null): array
    {
        $facility = $this->requireFacility($staff);

        $readings = [];

        foreach (SerologyMarker::cases() as $marker) {
            $readings[$marker->value] = MarkerResult::from($payload[$marker->value]);
        }

        $reactive = in_array(MarkerResult::Reactive, $readings, true);
        $verifiedBy = $reactive ? ($verifier ?? ($staff->can('lab.verify_serology') ? $staff : null)) : null;
        $awaiting = $reactive && $verifiedBy === null;

        [$donation, $amended, $settled, $referral] = DB::transaction(function () use ($staff, $facility, $donationId, $readings, $reactive, $correcting, $verifiedBy, $awaiting): array {
            $locked = $this->lockOrFail($donationId, $facility);

            $this->guardResultsWritable($locked, ClearanceKind::Tti);

            $amended = $this->laboratoryRepository->serologyFor($locked->id) !== null;

            // Saved once, changed only through an approved correction request.
            if (! $correcting && $amended) {
                throw $this->refuse(
                    409,
                    'correction_required',
                    'The serology panel is already recorded. Request a correction to change it.'
                );
            }

            $this->laboratoryRepository->upsertSerology($locked->id, [
                ...$readings,
                'recorded_by' => $staff->id,
                'recorded_at' => now(),
                'verified_by' => $verifiedBy?->id,
                'verified_at' => $verifiedBy === null ? null : now(),
            ]);

            // Saved and nothing more: no clearance, no rejection, no referral.
            // The missing TTI clearance keeps the unit's bags in quarantine.
            if ($awaiting) {
                return [$locked, $amended, ['result' => null, 'adopted' => false], null];
            }

            if ($reactive) {
                return [$locked, $amended, ['result' => TestResult::Reactive->value, 'adopted' => false], $this->rejectForReactive($locked, $staff, $facility)];
            }

            // A saved non-reactive panel is TTI Testing's clearance. It can
            // still be corrected, with approval, while the donation's bags
            // are in quarantine — see CorrectionService.
            $this->clearanceRepository->issue($locked->id, ClearanceKind::Tti, $staff, 'serology');

            return [$locked, $amended, $this->settle($locked, $staff), null];
        });

        // Outcome only. Which marker is never written to the audit trail:
        // the log is read far more widely than the referral list.
        $this->auditLogger->record($staff, 'laboratory.serology_recorded', $donation, [
            'facility_id' => $facility->id,
            'outcome' => $reactive ? MarkerResult::Reactive->value : MarkerResult::NonReactive->value,
            'awaiting_verification' => $awaiting,
            'amended' => $amended,
            'result' => $settled['result'],
        ]);

        if ($referral !== null) {
            $this->recordReactiveTookEffect($staff, $donation, $facility, $referral);
        }

        if ($awaiting) {
            $this->askForVerification($donation, $facility, $staff);
        }

        $this->recordAdoption($staff, $donation, $facility, $settled);

        return [
            'message' => match (true) {
                $awaiting => 'Serology saved as reactive. It takes effect only when the Laboratory Supervisor verifies it against the bench record. Until then the donation is not rejected, the donor is not deferred and the unit stays in quarantine.',
                $referral !== null => 'Serology recorded. The donation has been rejected, the donor permanently deferred and referred for counselling. Send the donor a contact request from Counselling referrals.',
                $settled['result'] === TestResult::Passed->value => 'Serology recorded. Both sections are cleared; its units may leave quarantine.',
                default => 'Serology recorded and cleared.',
            },
            'data' => $this->format($this->findOrFail($donationId, $facility), $staff),
        ];
    }

    /**
     * Verify a reactive panel a technologist saved, so that it takes effect.
     *
     * The Laboratory Supervisor has checked the reading against the bench
     * record. Everything recordSerology() held back now happens, in one
     * transaction: the donation is rejected, the donor permanently deferred
     * and referred for counselling.
     *
     * @return array<string, mixed>
     */
    public function verifySerology(User $staff, int $donationId): array
    {
        $facility = $this->requireFacility($staff);

        [$donation, $referral] = DB::transaction(function () use ($staff, $facility, $donationId): array {
            $locked = $this->lockOrFail($donationId, $facility);
            $serology = $this->awaitingVerificationOrFail($locked);

            $serology->verified_by = $staff->id;
            $serology->verified_at = now();
            $serology->save();

            return [$locked, $this->rejectForReactive($locked, $staff, $facility)];
        });

        $this->auditLogger->record($staff, 'laboratory.serology_verified', $donation, [
            'facility_id' => $facility->id,
        ]);

        $this->recordReactiveTookEffect($staff, $donation, $facility, $referral);

        return [
            'message' => 'Verified. The donation has been rejected, the donor permanently deferred and referred for counselling. Send the donor a contact request from Counselling referrals.',
            'data' => $this->format($this->findOrFail($donationId, $facility), $staff),
        ];
    }

    /**
     * Return a reactive panel awaiting verification for re-entry.
     *
     * The reading did not match the bench record. Nothing had happened on it,
     * so it is deleted: the unit goes back to the testing queue with no panel,
     * and whoever re-enters it starts afresh. The audit trail keeps that it
     * happened and who saved it, never which marker it named.
     *
     * @return array<string, mixed>
     */
    public function returnSerology(User $staff, int $donationId): array
    {
        $facility = $this->requireFacility($staff);

        [$donation, $recordedBy] = DB::transaction(function () use ($facility, $donationId): array {
            $locked = $this->lockOrFail($donationId, $facility);
            $serology = $this->awaitingVerificationOrFail($locked);
            $recordedBy = $serology->recorded_by;

            $serology->delete();

            return [$locked, $recordedBy];
        });

        $this->auditLogger->record($staff, 'laboratory.serology_returned', $donation, [
            'facility_id' => $facility->id,
            'recorded_by' => $recordedBy,
        ]);

        return [
            'message' => 'Returned for re-entry. The panel is cleared and the unit is back in the testing queue.',
            'data' => $this->format($this->findOrFail($donationId, $facility), $staff),
        ];
    }

    /**
     * Declare which components the donation was separated into.
     *
     * This is the declaration blood-unit intake is constrained to. Inventory
     * may record up to `quantity` units per component and no more, so a bag
     * cannot be booked in for a component the laboratory never produced.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function declareComponents(User $staff, int $donationId, array $payload, bool $correcting = false): array
    {
        $facility = $this->requireFacility($staff);

        $donation = DB::transaction(function () use ($staff, $facility, $donationId, $payload, $correcting): Donation {
            $locked = $this->lockOrFail($donationId, $facility);

            // Separating the unit and testing a sample are two things the bench
            // does at the same time, so neither waits on the other. The order
            // is re-imposed where it actually matters, at the shelf: a unit is
            // booked in quarantined and released only on both clearance
            // tokens.
            if (! in_array($locked->status, [DonationStatus::Collected, DonationStatus::Tested], true)) {
                throw $this->refuse(
                    409,
                    'donation_not_collected',
                    "A donation that is {$locked->status->label()} is not ready for processing."
                );
            }

            // Redeclaring after units exist would let the declaration drift
            // below what inventory already recorded against it.
            if ($locked->bloodUnits()->exists()) {
                throw $this->refuse(
                    409,
                    'units_already_recorded',
                    'Inventory has already recorded units for this donation, so the component breakdown is fixed.'
                );
            }

            if (! $correcting && $this->laboratoryRepository->hasComponents($locked->id)) {
                throw $this->refuse(
                    409,
                    'correction_required',
                    'The component breakdown is already recorded. Request a correction to change it.'
                );
            }

            $this->laboratoryRepository->replaceComponents($locked->id, $payload['components'], $staff->id);

            return $locked;
        });

        $this->auditLogger->record($staff, 'laboratory.components_declared', $donation, [
            'facility_id' => $facility->id,
            'components' => count($payload['components']),
        ]);

        return [
            'message' => 'Component breakdown recorded.',
            'data' => $this->format($this->findOrFail($donationId, $facility), $staff),
        ];
    }

    /**
     * Clear a donation for issue, or reject it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateStatus(User $staff, int $donationId, string $status, array $payload = []): array
    {
        $facility = $this->requireFacility($staff);
        $target = DonationStatus::from($status);
        $reason = isset($payload['rejection_reason']) ? trim((string) $payload['rejection_reason']) : null;

        $donation = DB::transaction(function () use ($facility, $donationId, $target, $reason): Donation {
            $locked = $this->lockOrFail($donationId, $facility);

            match ($target) {
                DonationStatus::Completed => $this->guardReadyToComplete($locked),
                DonationStatus::Rejected => $this->guardRejectable($locked),
                default => throw $this->refuse(
                    409,
                    'invalid_transition',
                    'The laboratory may only complete or reject a donation.'
                ),
            };

            $locked->status = $target;

            if ($target === DonationStatus::Rejected) {
                $locked->rejection_reason = $reason;
            }

            $locked->save();

            return $locked;
        });

        $this->auditLogger->record($staff, 'laboratory.donation_'.$target->value, $donation, [
            'facility_id' => $facility->id,
            'rejection_reason' => $target === DonationStatus::Rejected ? $reason : null,
        ]);

        return [
            'message' => $target === DonationStatus::Completed
                ? 'Processing complete. Inventory may now book its units into quarantine.'
                : 'Donation rejected.',
            'data' => $this->format($this->findOrFail($donationId, $facility), $staff),
        ];
    }

    /**
     * Write the overall outcome once both departments have cleared the donation.
     *
     * Must run inside the caller's transaction, with the donation locked. The
     * tokens are issued by their own departments' acts — a concordant typing,
     * a cleared serology panel — and this only notices when both exist. Then
     * the summary row the rest of the system reads is written as `passed`, and
     * a donation still at `collected` moves to `tested`. One that Processing
     * has already completed stays `completed`: its units are on the shelf in
     * quarantine, and the tokens are what release them.
     *
     * @return array{result: string|null, adopted: bool}
     */
    private function settle(Donation $locked, User $staff): array
    {
        $immunohematology = $this->laboratoryRepository->immunohematologyFor($locked->id);
        $serology = $this->laboratoryRepository->serologyFor($locked->id);

        if ($immunohematology === null || $serology === null || $serology->isReactive()
            || ! $this->clearanceRepository->hasBoth($locked->id)) {
            return ['result' => null, 'adopted' => false];
        }

        $this->laboratoryRepository->upsertTestResult($locked->id, [
            'recorded_by' => $staff->id,
            'blood_type_id' => $immunohematology->blood_type_id,
            'result' => TestResult::Passed,
            'tested_at' => $immunohematology->recorded_at->max($serology->recorded_at),
            'notes' => $immunohematology->notes,
        ]);

        if ($locked->status === DonationStatus::Collected) {
            $locked->status = DonationStatus::Tested;
            $locked->save();
        }

        // Adoption happens where the typing is cleared, not here.
        return ['result' => TestResult::Passed->value, 'adopted' => false];
    }

    /**
     * Reject a donation for a reactive marker and refer its donor for counselling.
     *
     * Must run inside the caller's transaction, with the donation locked. It
     * applies to a donation Processing has already completed too: a legacy
     * donation handed over before testing finished. Stock Intake refuses an
     * uncleared donation, and any of its bags booked into quarantine before
     * the Release step was removed (2026-10-22) can only be discarded.
     *
     * THE ONE EXCEPTION TO THE DEPARTMENT SPLIT. Testing does not hold
     * `lab.update_status`, and still writes `rejected` here. It is not a
     * discretionary status change: it is the automatic consequence of a
     * reading Testing is the only department entitled to record. A reactive
     * donation left for Processing to reject by hand would sit in a queue
     * looking like any other bag.
     *
     * The referral row is also the donor's permanent deferral — see
     * DonorDeferralRepository — so it is written here, with the rejection,
     * and cannot exist without it.
     */
    private function rejectForReactive(Donation $locked, User $staff, Facility $facility): CounsellingReferral
    {
        // Keep the summary row truthful where there is a typing to write it
        // with: from immunohematology, or from a legacy result recorded before
        // the sections existed. With neither, no summary is written — the
        // rejection is the outcome, and the typing was never done.
        $bloodTypeId = $this->laboratoryRepository->immunohematologyFor($locked->id)?->blood_type_id
            ?? $this->laboratoryRepository->testResultFor($locked->id)?->blood_type_id;

        if ($bloodTypeId !== null) {
            $this->laboratoryRepository->upsertTestResult($locked->id, [
                'recorded_by' => $staff->id,
                'blood_type_id' => $bloodTypeId,
                'result' => TestResult::Reactive,
                'tested_at' => now(),
            ]);
        }

        // A donation Processing rejected while the result waited for
        // verification keeps its own reason; the donor is deferred either way.
        if ($locked->status !== DonationStatus::Rejected) {
            $locked->status = DonationStatus::Rejected;
            $locked->rejection_reason = self::REACTIVE_REJECTION_REASON;
            $locked->save();
        }

        return $this->laboratoryRepository->openReferral($locked, $facility->id);
    }

    /**
     * The panel on this donation that is waiting for verification, or 409.
     */
    private function awaitingVerificationOrFail(Donation $locked): DonationSerology
    {
        $serology = $this->laboratoryRepository->serologyFor($locked->id);

        if ($serology === null || ! $serology->isAwaitingVerification()) {
            throw $this->refuse(
                409,
                'nothing_to_verify',
                'This donation has no reactive serology result waiting for verification.'
            );
        }

        return $serology;
    }

    /**
     * Audit what a reactive result set off once it took effect.
     */
    private function recordReactiveTookEffect(User $staff, Donation $donation, Facility $facility, CounsellingReferral $referral): void
    {
        $this->auditLogger->record($staff, 'laboratory.donation_rejected', $donation, [
            'facility_id' => $facility->id,
            'automatic' => true,
            'cause' => 'reactive_serology',
        ]);

        $this->auditLogger->record($staff, 'referral.opened', $referral, [
            'facility_id' => $facility->id,
            'donation_id' => $donation->id,
        ]);
    }

    /**
     * Tell whoever may verify that a reactive result is waiting.
     *
     * After the commit, and never fatal: the result is saved and listed under
     * Awaiting verification whether or not the notice gets through.
     */
    private function askForVerification(Donation $donation, Facility $facility, User $recorder): void
    {
        $roles = array_map(
            fn (StaffRole $role): string => $role->value,
            DepartmentPermissions::rolesHolding('lab.verify_serology')
        );

        try {
            $recipients = User::query()
                ->where('facility_id', $facility->id)
                ->whereKeyNot($recorder->id)
                ->where(function ($query) use ($roles): void {
                    $query->whereIn('staff_role', $roles);

                    foreach ($roles as $role) {
                        $query->orWhereJsonContains('additional_staff_roles', $role);
                    }
                })
                ->get();

            Notification::send($recipients, new SerologyVerificationRequested(
                $donation,
                $donation->collection()->value('donation_barcode'),
                $this->staffName($recorder) ?? 'a technologist'
            ));
        } catch (Throwable $exception) {
            Log::warning('Could not notify the verifiers of a reactive serology result.', [
                'donation_id' => $donation->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Audit a blood type newly written onto the donor profile.
     *
     * @param  array{result: string|null, adopted: bool}  $settled
     */
    private function recordAdoption(User $staff, Donation $donation, Facility $facility, array $settled): void
    {
        if (! $settled['adopted']) {
            return;
        }

        $this->auditLogger->record($staff, 'donor.blood_type_verified', $donation, [
            'facility_id' => $facility->id,
            'blood_type_id' => $donation->donorProfile?->blood_type_id,
        ]);
    }

    /**
     * Refuse a test section on a donation that is not the Testing department's to test.
     *
     * A finished donation is locked either way: a completed one may already be
     * stock, and a rejected one may already have deferred and referred its
     * donor. Rewriting its results would rewrite the justification for both.
     */
    private function guardResultsWritable(Donation $donation, ClearanceKind $kind): void
    {
        // A rejected donation may already have deferred and referred its
        // donor. Rewriting its results would rewrite the justification.
        if ($donation->status === DonationStatus::Rejected) {
            throw $this->refuse(
                409,
                'results_locked',
                'This donation has been rejected, so its test results can no longer be changed.'
            );
        }

        // Results belong to a donation the counter has finished with. A
        // donation still being screened has no bag to test. A completed one
        // is still testable: Processing does not wait for the laboratory.
        if (! in_array($donation->status, [DonationStatus::Collected, DonationStatus::Tested, DonationStatus::Completed], true)) {
            throw $this->refuse(
                409,
                'donation_not_collected',
                "A donation that is {$donation->status->label()} is not ready for testing."
            );
        }

        // A token is what releases units. The result it stands on cannot be
        // rewritten underneath it — by anyone.
        if ($this->clearanceRepository->has($donation->id, $kind)) {
            throw $this->refuse(
                409,
                'results_cleared',
                "{$kind->label()} has already been issued for this donation, so this section can no longer be changed."
            );
        }
    }

    /**
     * Refuse to complete a donation Processing has not finished with.
     *
     * Completing hands labelled bags to Issuance, which books them straight
     * into stock (project owner, 2026-10-22). So the bags wait in Processing's
     * quarantine until Testing has cleared the donation and Processing has
     * printed the final labels (FinalLabelService): both clearance tokens and
     * the labels are conditions here. A correction that changes what a label
     * says clears `labelled_at`, so relabelling comes before hand-over too.
     */
    private function guardReadyToComplete(Donation $donation): void
    {
        if ($donation->status->isTerminal()) {
            throw $this->refuse(
                409,
                'donation_already_final',
                "This donation is already {$donation->status->label()}."
            );
        }

        if (! in_array($donation->status, [DonationStatus::Collected, DonationStatus::Tested], true)) {
            throw $this->refuse(
                409,
                'donation_not_collected',
                "A donation that is {$donation->status->label()} is not ready for processing."
            );
        }

        if (! $this->laboratoryRepository->hasComponents($donation->id)) {
            throw $this->refuse(
                409,
                'components_missing',
                'Declare the component breakdown before completing the donation.'
            );
        }

        if (! $this->clearanceRepository->has($donation->id, ClearanceKind::Tti)) {
            throw $this->refuse(
                409,
                'tti_not_cleared',
                'TTI Testing has not cleared this donation. Its bags stay with Processing until it does.'
            );
        }

        if (! $this->clearanceRepository->has($donation->id, ClearanceKind::Immunohematology)) {
            throw $this->refuse(
                409,
                'immunohematology_not_cleared',
                'Immunohematology has not cleared this donation. Its bags stay with Processing until it does.'
            );
        }

        if ($donation->labelled_at === null) {
            throw $this->refuse(
                409,
                'labels_not_printed',
                'Print and affix the final labels before handing the bags over.'
            );
        }
    }

    /**
     * Refuse to reject a donation that has already been cleared.
     */
    private function guardRejectable(Donation $donation): void
    {
        if ($donation->status->isTerminal()) {
            throw $this->refuse(
                409,
                'donation_already_final',
                "This donation is already {$donation->status->label()}."
            );
        }

        // A donation the counter has not finished with is not the laboratory's
        // to reject: turning a donor away before they have been screened is a
        // Collection decision, and only that department closes the
        // appointment the donor booked.
        if (! in_array($donation->status, [DonationStatus::Collected, DonationStatus::Tested], true)) {
            throw $this->refuse(
                409,
                'donation_not_collected',
                "A donation that is {$donation->status->label()} is not the laboratory's to reject."
            );
        }
    }

    /**
     * Write a newly determined blood type onto a donor profile that had none.
     *
     * Only fills a blank, and only from a `passed` result. It overwrites
     * nothing, so it does not disturb the mismatch rule next door: a profile
     * that already carries a type still wins a disagreement by refusing the
     * result outright, and correcting it stays a Collection action.
     *
     * Without this a donor registered at the counter without a blood type was
     * stuck: the laboratory could type their bag, but the type never reached
     * the profile, and `blood_units.blood_type_id` derives from the profile —
     * so inventory refused the donation with `donor_blood_type_missing` and the
     * donation could never become stock.
     *
     * A `reactive` result is not a reliable typing to act on, so it leaves the
     * profile blank. Nor is the screening table's fingerprick reading, which
     * never reaches this method at all.
     *
     * @return bool Whether the profile was actually filled in.
     */
    private function adoptVerifiedBloodType(Donation $donation, int $typedBloodTypeId, TestResult $result): bool
    {
        if (! $result->clearsForIssue()) {
            return false;
        }

        $profile = $donation->donorProfile;

        if ($profile === null || $profile->blood_type_id !== null) {
            return false;
        }

        $profile->blood_type_id = $typedBloodTypeId;
        $profile->save();

        return true;
    }

    /**
     * Refuse a typed blood type that contradicts the donor's own record.
     *
     * A person's blood type does not change, so a mismatch means one of the two
     * records is wrong — and blood_units derives its type from the donor
     * profile. Letting the donation proceed would put a unit into stock labelled
     * with a type the laboratory did not read off the bag. Correcting the donor
     * profile is a Collection action, so this refuses rather than silently
     * picking a winner.
     */
    private function guardBloodTypeMatchesDonor(Donation $donation, int $typedBloodTypeId): void
    {
        $donorBloodTypeId = $donation->donorProfile?->blood_type_id;

        if ($donorBloodTypeId === null || (int) $donorBloodTypeId === $typedBloodTypeId) {
            return;
        }

        throw $this->refuse(
            409,
            'blood_type_mismatch',
            'The typed blood type does not match the donor record. Have Collection correct the donor profile before recording this result.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Donation $donation, User $staff): array
    {
        $result = $donation->relationLoaded('testResult') ? $donation->testResult : $donation->testResult()->first();
        $immunohematology = $donation->relationLoaded('immunohematology')
            ? $donation->immunohematology
            : $donation->immunohematology()->with(['bloodType', 'recorder'])->first();
        $serology = $donation->relationLoaded('serology')
            ? $donation->serology
            : $donation->serology()->with(['recorder', 'verifier'])->first();
        $collection = $donation->relationLoaded('collection') ? $donation->collection : $donation->collection()->first();
        $screening = $donation->relationLoaded('screening')
            ? $donation->screening
            : $donation->screening()->with('fingerprickBloodType')->first();

        return [
            'id' => $donation->id,
            'donation_date' => $donation->donation_date?->toISOString(),
            'status' => $donation->status?->value,
            'status_label' => $donation->status?->label(),
            'owning_department' => $donation->status?->owningDepartment()?->value,
            'volume_ml' => $donation->volume_ml,
            'rejection_reason' => $donation->rejection_reason,
            // Blind to the laboratory roles, who match sample to record by the
            // donation barcode below. See DonorBlinding.
            'donor' => DonorBlinding::block(
                $donation->donorProfile?->donor,
                $donation->donorProfile?->bloodType?->code,
                $staff
            ),

            // The tube's identity, so the bench can match sample to record.
            'collection' => $collection === null ? null : [
                'donation_barcode' => $collection->donation_barcode,
                'blood_bag_type' => $collection->blood_bag_type?->value,
                'blood_bag_type_label' => $collection->blood_bag_type?->label(),
                'started_at' => $collection->started_at?->toISOString(),
                'ended_at' => $collection->ended_at?->toISOString(),
            ],

            // Reference only. Never used to pre-fill the typing below.
            'fingerprick_blood_type' => $screening?->fingerprickBloodType?->code,

            'test_result' => $result === null ? null : [
                'result' => $result->result?->value,
                'result_label' => $result->result?->label(),
                'clears_for_issue' => $result->result?->clearsForIssue(),
                'blood_type' => $result->bloodType?->code,
                'tested_at' => $result->tested_at?->toISOString(),
                'notes' => $result->notes,
                // Recorded under the old single-result screen, before the
                // sections existed. Processing cannot clear it until Testing
                // records the panel.
                'is_legacy' => $serology === null,
            ],

            'immunohematology' => $immunohematology === null ? null : [
                'blood_type_id' => $immunohematology->blood_type_id,
                'blood_type' => $immunohematology->bloodType?->code,
                'forward_group' => $immunohematology->forward_group?->value,
                'reverse_group' => $immunohematology->reverse_group?->value,
                'antibody_screen' => $immunohematology->antibody_screen?->value,
                'antibody_screen_label' => $immunohematology->antibody_screen?->label(),
                // Null for a typing recorded before grouping was itemised.
                'clearance_hold' => $immunohematology->forward_group === null ? null : $immunohematology->clearanceHold(),
                'notes' => $immunohematology->notes,
                'recorded_by' => $this->staffName($immunohematology->recorder),
                'recorded_at' => $immunohematology->recorded_at?->toISOString(),
            ],

            'serology' => $serology === null ? null : $this->formatSerology($serology, $staff),

            // Which departments have cleared this donation. A unit leaves
            // quarantine only when both have.
            'clearances' => $this->formatClearances($donation),

            // When Processing last printed the final labels, which the
            // hand-over requires. Read only when there is one, so the queue
            // pays nothing for unlabelled rows.
            'labelled_at' => $donation->labelled_at?->toISOString(),
            'labelled_by' => $donation->labelled_by === null ? null : $this->staffName($donation->labeller),

            // The sticker every bag of this donation carries, and each bag's
            // number built from it — what the Phase 1 label prints.
            'donation_barcode' => BagNumbers::barcode($donation),

            'components' => $this->formatComponents($donation),
        ];
    }

    /**
     * One entry per bag, with the number Processing prints on its Phase 1 label.
     *
     * `quantity` is 1 for every bag declared with a volume; a breakdown
     * recorded before volumes were kept has a null volume, its original count,
     * and no bag number.
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatComponents(Donation $donation): array
    {
        $numbers = BagNumbers::forRows($donation);

        return $this->laboratoryRepository
            ->componentsFor($donation->id)
            ->map(fn (DonationComponent $c): array => [
                'id' => $c->id,
                'component_id' => $c->component_id,
                'component' => $c->component?->name,
                'volume_ml' => $c->volume_ml,
                'quantity' => $c->quantity,
                'bag_number' => $numbers[$c->id] ?? null,
            ])->all();
    }

    /**
     * The serology section, with per-marker readings only for the Testing department.
     *
     * Processing needs to know whether the panel passed; it does not need to
     * know which infection a donor carries. The readings go to whoever may
     * record them — Testing — and to nobody else.
     *
     * Public for TTI Testing's results list (SerologyResultService), whose
     * rows must say exactly what this section says.
     *
     * @return array<string, mixed>
     */
    public function formatSerology(DonationSerology $serology, User $staff): array
    {
        $reactive = $serology->isReactive();
        $awaiting = $serology->isAwaitingVerification();
        $testing = $staff->can('lab.record_serology');

        return [
            // An unverified reactive reading has set nothing off. Outside
            // Testing it is not yet a reactive result, only a wait.
            'outcome' => match (true) {
                $awaiting && ! $testing => 'awaiting_verification',
                $reactive => MarkerResult::Reactive->value,
                default => MarkerResult::NonReactive->value,
            },
            'outcome_label' => match (true) {
                $awaiting && ! $testing => 'Awaiting verification',
                $reactive => MarkerResult::Reactive->label(),
                default => MarkerResult::NonReactive->label(),
            },
            'awaiting_verification' => $awaiting,
            'recorded_by' => $this->staffName($serology->recorder),
            'recorded_at' => $serology->recorded_at?->toISOString(),
            'verified_by' => $this->staffName($serology->verifier),
            'verified_at' => $serology->verified_at?->toISOString(),
            'markers' => $testing
                ? array_map(fn (SerologyMarker $marker): array => [
                    'marker' => $marker->value,
                    'label' => $marker->label(),
                    'result' => $serology->resultFor($marker)?->value,
                    'result_label' => $serology->resultFor($marker)?->label(),
                ], SerologyMarker::cases())
                : null,
        ];
    }

    /**
     * Public for Processing's Processed list (ProcessedDonationService), whose
     * rows must say exactly what the bench says.
     *
     * @return array<string, bool>
     */
    public function formatClearances(Donation $donation): array
    {
        $issued = ($donation->relationLoaded('clearances') ? $donation->clearances : $donation->clearances()->get())
            ->map(fn ($clearance): string => $clearance->kind->value)
            ->all();

        $clearances = [];

        foreach (ClearanceKind::cases() as $kind) {
            $clearances[$kind->value] = in_array($kind->value, $issued, true);
        }

        return $clearances;
    }

    private function staffName(?User $user): ?string
    {
        return $user === null ? null : $user->signatureName();
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Re-read one of this facility's donations under a lock, or 404.
     */
    private function lockOrFail(int $donationId, Facility $facility): Donation
    {
        return $this->laboratoryRepository->lockDonation($donationId, $facility->id)
            ?? throw $this->refuse(404, 'donation_not_found', 'That donation was not found at your facility.');
    }

    /**
     * Find one of this facility's donations, or 404.
     */
    private function findOrFail(int $donationId, Facility $facility): Donation
    {
        return $this->laboratoryRepository->findDonation($donationId, $facility->id)
            ?? throw $this->refuse(404, 'donation_not_found', 'That donation was not found at your facility.');
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
