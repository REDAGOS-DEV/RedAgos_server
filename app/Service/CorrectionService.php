<?php

namespace App\Service;

use App\Enums\BloodUnitStatus;
use App\Enums\ClearanceKind;
use App\Enums\CorrectionSubject;
use App\Enums\DonationStatus;
use App\Models\BloodCollection;
use App\Models\CorrectionRequest;
use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\DonationImmunohematology;
use App\Models\DonationScreening;
use App\Models\DonationSerology;
use App\Models\DonationTestResult;
use App\Models\Facility;
use App\Models\User;
use App\Repository\ClearanceRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changing a saved record: the writer asks, someone else in the department decides.
 *
 * A staff member who entered something wrong cannot save over it. They file
 * the corrected values with a reason; the department's approver — the senior
 * role named by Department::correctionApprover() — or the Center Admin
 * approves or rejects it. An approved correction is applied through the very
 * write that recorded the original, as the requester, so every guard and hard
 * rule that bound the first save binds the correction too: a reactive result
 * stays reactive. A cleared result may be corrected only while none of the
 * donation's bags has left quarantine; approving it revokes the old clearance
 * and the corrected result earns a new one only if it qualifies.
 *
 * Nobody approves their own request, and an approver's own request goes to
 * the Center Admin rather than to a peer.
 */
class CorrectionService
{
    public function __construct(
        private readonly CollectionService $collectionService,
        private readonly LaboratoryService $laboratoryService,
        private readonly ClearanceRepository $clearanceRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Page correction requests: the caller's own, or those they may decide.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $viewer, array $filters, int $perPage): LengthAwarePaginator
    {
        $facility = $this->requireFacility($viewer);
        $scope = $filters['scope'] ?? 'mine';

        $query = CorrectionRequest::query()
            ->where('facility_id', $facility->id)
            ->with(['requester', 'reviewer', 'donation.collection'])
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('id');

        if ($scope === 'review') {
            $query->where('requested_by', '!=', $viewer->id);

            if (! $viewer->is_supervisor) {
                // Only the subjects whose department this viewer approves for.
                $subjects = array_values(array_filter(
                    CorrectionSubject::cases(),
                    fn (CorrectionSubject $subject): bool => $viewer->staff_role !== null
                        && $subject->department()->correctionApprover() === $viewer->staff_role
                ));

                $query->whereIn('subject', array_map(fn (CorrectionSubject $s): string => $s->value, $subjects))
                    // A peer's own request goes to the Center Admin.
                    ->whereHas('requester', fn ($q) => $q->where(fn ($inner) => $inner
                        ->whereNull('staff_role')
                        ->orWhere('staff_role', '!=', $viewer->staff_role?->value)));
            }
        } else {
            $query->where('requested_by', $viewer->id);
        }

        return $query->paginate($perPage)
            ->through(fn (CorrectionRequest $correction): array => $this->format($correction, $viewer));
    }

    /**
     * File a correction to a record the caller's role writes.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function request(User $requester, int $donationId, CorrectionSubject $subject, array $changes, string $reason): array
    {
        $facility = $this->requireFacility($requester);

        if (! $requester->can($subject->writeAbility())) {
            throw $this->refuse(403, 'not_your_record', "Your role does not record the {$subject->label()}, so it cannot correct it.");
        }

        $correction = DB::transaction(function () use ($requester, $facility, $donationId, $subject, $changes, $reason): CorrectionRequest {
            $donation = Donation::query()
                ->where('facility_id', $facility->id)
                ->whereKey($donationId)
                ->lockForUpdate()
                ->first()
                ?? throw $this->refuse(404, 'donation_not_found', 'That donation was not found at your facility.');

            $previous = $this->snapshot($donation, $subject)
                ?? throw $this->refuse(409, 'nothing_to_correct', "No {$subject->label()} is recorded for this donation yet — record it directly.");

            $this->guardCorrectable($donation, $subject);

            $pending = CorrectionRequest::query()
                ->where('donation_id', $donation->id)
                ->where('subject', $subject->value)
                ->where('status', 'pending')
                ->exists();

            if ($pending) {
                throw $this->refuse(409, 'correction_pending', "A correction to this {$subject->label()} is already waiting for a decision.");
            }

            $validated = $this->validateChanges($subject, $changes, $requester, $donation->id);

            return CorrectionRequest::create([
                'facility_id' => $facility->id,
                'donation_id' => $donation->id,
                'subject' => $subject,
                'requested_by' => $requester->id,
                'reason' => trim($reason),
                'changes' => $validated,
                'previous' => $previous,
            ]);
        });

        $this->auditLogger->record($requester, 'correction.requested', $correction, [
            'facility_id' => $facility->id,
            'donation_id' => $donationId,
            'subject' => $subject->value,
            'fields' => $this->changedFields($correction),
        ]);

        return [
            'message' => 'Correction requested. It is applied once it is approved.',
            'data' => $this->format($correction->fresh(['requester', 'reviewer', 'donation.collection']), $requester),
        ];
    }

    /**
     * Approve a correction and apply it.
     *
     * The write runs as the requester, through the original path, inside this
     * transaction: if any guard refuses it — the result was cleared meanwhile,
     * the run was validated, the bag was drawn — nothing is applied and the
     * request stays pending for the approver to reject.
     *
     * @return array<string, mixed>
     */
    public function approve(User $approver, int $correctionId, ?string $note): array
    {
        $facility = $this->requireFacility($approver);

        $correction = DB::transaction(function () use ($approver, $facility, $correctionId, $note): CorrectionRequest {
            $correction = $this->lockPending($correctionId, $facility);
            $this->guardMayDecide($approver, $correction);

            $requester = $correction->requester()->firstOrFail();
            $changes = $correction->changes;
            $donationId = $correction->donation_id;

            // Re-judged now, not as it stood when the request was filed: a bag
            // may have left quarantine in between.
            $donation = Donation::query()->whereKey($donationId)->lockForUpdate()->firstOrFail();
            $this->guardCorrectable($donation, $correction->subject);

            // The clearance stood on the result being replaced, so it goes
            // with it. The corrected write issues a new one if it qualifies;
            // if that write is refused, the transaction restores this one.
            if (($kind = $this->clearanceKind($correction->subject)) !== null) {
                $this->clearanceRepository->revoke($donationId, $kind, $approver, "Correction #{$correction->id}");

                // `tested` and the `passed` summary both stand on the two
                // tokens. With one revoked they no longer hold; settle()
                // restores both if the corrected result clears again.
                DonationTestResult::query()->where('donation_id', $donationId)->delete();

                if ($donation->status === DonationStatus::Tested) {
                    $donation->status = DonationStatus::Collected;
                    $donation->save();
                }
            }

            match ($correction->subject) {
                CorrectionSubject::Screening => $this->collectionService->recordScreening($requester, $donationId, $changes, correcting: true),
                CorrectionSubject::Collection => $this->collectionService->correctCollection($requester, $donationId, $changes),
                CorrectionSubject::Immunohematology => $this->laboratoryService->recordImmunohematology($requester, $donationId, $changes, correcting: true),
                CorrectionSubject::Serology => $this->laboratoryService->recordSerology($requester, $donationId, $changes, correcting: true),
                CorrectionSubject::Components => $this->laboratoryService->declareComponents($requester, $donationId, $changes, correcting: true),
            };

            $correction->status = 'approved';
            $correction->reviewed_by = $approver->id;
            $correction->reviewed_at = now();
            $correction->review_note = $note === null ? null : trim($note);
            $correction->save();

            return $correction;
        });

        $context = [
            'facility_id' => $facility->id,
            'donation_id' => $correction->donation_id,
            'subject' => $correction->subject->value,
        ];

        $this->auditLogger->record($approver, 'correction.approved', $correction, $context);

        // Field names only. A serology correction's values are marker readings,
        // and the audit trail is read far more widely than the laboratory.
        $this->auditLogger->record($approver, 'correction.applied', $correction->donation()->first(), [
            ...$context,
            'correction_id' => $correction->id,
            'requested_by' => $correction->requested_by,
            'fields' => $this->changedFields($correction),
        ]);

        return [
            'message' => 'Correction approved and applied.',
            'data' => $this->format($correction->fresh(['requester', 'reviewer', 'donation.collection']), $approver),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(User $approver, int $correctionId, string $note): array
    {
        $facility = $this->requireFacility($approver);

        $correction = DB::transaction(function () use ($approver, $facility, $correctionId, $note): CorrectionRequest {
            $correction = $this->lockPending($correctionId, $facility);
            $this->guardMayDecide($approver, $correction);

            $correction->status = 'rejected';
            $correction->reviewed_by = $approver->id;
            $correction->reviewed_at = now();
            $correction->review_note = trim($note);
            $correction->save();

            return $correction;
        });

        $this->auditLogger->record($approver, 'correction.rejected', $correction, [
            'facility_id' => $facility->id,
            'donation_id' => $correction->donation_id,
            'subject' => $correction->subject->value,
        ]);

        return [
            'message' => 'Correction rejected. The record is unchanged.',
            'data' => $this->format($correction->fresh(['requester', 'reviewer', 'donation.collection']), $approver),
        ];
    }

    /**
     * Whether a user may decide on a correction.
     *
     * A supervisor may decide any request but their own. Otherwise the
     * decider must hold the approver role for the record's department, and
     * must not share the requester's role: an approver's own request goes to
     * the Center Admin, not to a colleague of equal standing.
     */
    public function mayDecide(User $user, CorrectionRequest $correction): bool
    {
        if ((int) $correction->requested_by === (int) $user->id) {
            return false;
        }

        if ($user->is_supervisor) {
            return true;
        }

        $approverRole = $correction->subject->department()->correctionApprover();

        if ($approverRole === null || $user->staff_role !== $approverRole) {
            return false;
        }

        $requesterRole = $correction->requester?->staff_role;

        return $requesterRole !== $approverRole;
    }

    private function guardMayDecide(User $user, CorrectionRequest $correction): void
    {
        if ((int) $correction->requested_by === (int) $user->id) {
            throw $this->refuse(409, 'self_approval', 'You cannot decide on your own correction request.');
        }

        if (! $this->mayDecide($user, $correction)) {
            throw $this->refuse(
                403,
                'not_the_approver',
                'This correction is decided by the '.($correction->subject->department()->correctionApprover()?->label() ?? 'Center Admin')
                .' of '.$correction->subject->department()->label().', or by the Center Admin.'
            );
        }
    }

    /**
     * The clearance a subject's result stands behind, if any.
     */
    private function clearanceKind(CorrectionSubject $subject): ?ClearanceKind
    {
        return match ($subject) {
            CorrectionSubject::Serology => ClearanceKind::Tti,
            CorrectionSubject::Immunohematology => ClearanceKind::Immunohematology,
            default => null,
        };
    }

    /**
     * Refuse a correction to a record that can no longer change.
     */
    private function guardCorrectable(Donation $donation, CorrectionSubject $subject): void
    {
        if ($subject === CorrectionSubject::Serology) {
            $serology = DonationSerology::query()->where('donation_id', $donation->id)->first();

            if ($serology?->isReactive()) {
                throw $this->refuse(
                    409,
                    'not_correctable',
                    'A reactive serology result can never be corrected. It has already rejected the donation.'
                );
            }
        }

        $kind = $this->clearanceKind($subject);

        // A cleared result may still be corrected while every bag is in
        // quarantine — the correction can change whether it clears. Once a
        // bag has been released, the result is what put it on the shelf.
        if ($kind !== null && $this->clearanceRepository->has($donation->id, $kind)) {
            $released = $donation->bloodUnits()
                ->whereIn('status', [
                    BloodUnitStatus::Available->value,
                    BloodUnitStatus::Reserved->value,
                    BloodUnitStatus::Issued->value,
                    BloodUnitStatus::Expired->value,
                ])
                ->exists();

            if ($released) {
                throw $this->refuse(
                    409,
                    'units_released',
                    'Units from this donation have already left quarantine, so this result can no longer be corrected.'
                );
            }
        }
    }

    /**
     * What the record says now, or null when there is nothing recorded to correct.
     *
     * @return array<string, mixed>|null
     */
    private function snapshot(Donation $donation, CorrectionSubject $subject): ?array
    {
        return match ($subject) {
            CorrectionSubject::Screening => DonationScreening::query()->where('donation_id', $donation->id)->first()?->only([
                'outcome', 'deferral_reason', 'systolic_bp', 'diastolic_bp', 'pulse_bpm', 'temperature_c',
                'weight_kg', 'haemoglobin_g_dl', 'fingerprick_blood_type_id', 'sleep', 'meal', 'meds', 'allergies',
                'general_appearance', 'skin', 'heent', 'heart_and_lungs', 'notes',
            ]),
            CorrectionSubject::Collection => ($collection = BloodCollection::query()->where('donation_id', $donation->id)->first()) === null ? null : [
                'blood_bag_type' => $collection->blood_bag_type?->value,
                'segment_number' => $collection->segment_number,
                'started_at' => $collection->started_at?->toISOString(),
                'ended_at' => $collection->ended_at?->toISOString(),
                'volume_ml' => $donation->volume_ml,
            ],
            CorrectionSubject::Immunohematology => DonationImmunohematology::query()->where('donation_id', $donation->id)->first()?->only([
                'blood_type_id', 'forward_group', 'reverse_group', 'antibody_screen', 'notes',
            ]),
            CorrectionSubject::Serology => DonationSerology::query()->where('donation_id', $donation->id)->first()?->only([
                'hiv', 'hbsag', 'hcv', 'syphilis', 'malaria',
            ]),
            CorrectionSubject::Components => ($rows = DonationComponent::query()->where('donation_id', $donation->id)->orderBy('id')->get())->isEmpty()
                ? null
                : ['components' => $rows->map(fn (DonationComponent $row): array => [
                    'component_id' => $row->component_id,
                    'volume_ml' => $row->volume_ml,
                ])->all()],
        };
    }

    /**
     * Validate the corrected values with the rules the original write passed.
     *
     * The original form request is built around the corrected payload and run
     * whole — prepareForValidation, rules and after-hooks — so a correction
     * can never save something the first write would have refused.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function validateChanges(CorrectionSubject $subject, array $changes, User $requester, int $donationId): array
    {
        $class = $subject->requestClass();

        /** @var FormRequest $form */
        $form = $class::create('/', 'POST', $changes);
        $form->setContainer(app())->setRedirector(app(Redirector::class));
        $form->setUserResolver(fn (): User => $requester);

        if (property_exists($form, 'correctingDonationId')) {
            $form->correctingDonationId = $donationId;
        }

        try {
            $form->validateResolved();
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $errors["changes.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        return $form->validated();
    }

    /**
     * The names of the fields a correction changes.
     *
     * @return array<int, string>
     */
    private function changedFields(CorrectionRequest $correction): array
    {
        $previous = $correction->previous ?? [];
        $fields = [];

        foreach ($correction->changes as $field => $value) {
            if (($previous[$field] ?? null) != $value) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private function lockPending(int $correctionId, Facility $facility): CorrectionRequest
    {
        $correction = CorrectionRequest::query()
            ->where('facility_id', $facility->id)
            ->whereKey($correctionId)
            ->lockForUpdate()
            ->first()
            ?? throw $this->refuse(404, 'correction_not_found', 'That correction request was not found.');

        if (! $correction->isPending()) {
            throw $this->refuse(409, 'correction_decided', "This correction was already {$correction->status}.");
        }

        return $correction;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(CorrectionRequest $correction, User $viewer): array
    {
        return [
            'id' => $correction->id,
            'donation_id' => $correction->donation_id,
            // Barcode, not name: the approver may be a blind laboratory role.
            'segment_number' => $correction->donation?->collection?->segment_number,
            'subject' => $correction->subject->value,
            'subject_label' => $correction->subject->label(),
            'department' => $correction->subject->department()->value,
            'status' => $correction->status,
            'reason' => $correction->reason,
            'changes' => $correction->changes,
            'previous' => $correction->previous,
            'changed_fields' => $this->changedFields($correction),
            'requested_by' => $this->name($correction->requester),
            'requested_at' => $correction->created_at?->toISOString(),
            'reviewed_by' => $this->name($correction->reviewer),
            'reviewed_at' => $correction->reviewed_at?->toISOString(),
            'review_note' => $correction->review_note,
            'can_decide' => $correction->isPending() && $this->mayDecide($viewer, $correction),
        ];
    }

    private function name(?User $user): ?string
    {
        return $user === null ? null : trim($user->first_name.' '.$user->last_name);
    }

    private function requireFacility(User $user): Facility
    {
        $user->loadMissing('facility');

        return $user->facility
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
