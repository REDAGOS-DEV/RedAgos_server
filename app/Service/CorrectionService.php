<?php

namespace App\Service;

use App\Enums\AllocationStatus;
use App\Enums\BillingStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\ClearanceKind;
use App\Enums\CorrectionSubject;
use App\Enums\CorrectionTarget;
use App\Enums\DonationStatus;
use App\Models\Billing;
use App\Models\BloodCollection;
use App\Models\BloodRequest;
use App\Models\CorrectionRequest;
use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\DonationImmunohematology;
use App\Models\DonationScreening;
use App\Models\DonationSerology;
use App\Models\DonationTestResult;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Repository\ClearanceRepository;
use App\Repository\InventoryRepository;
use App\Support\CorrectionValues;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
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
 *
 * A correction is about one target: a donation's record, a unit, a dispatched
 * allocation or a payment (CorrectionTarget). Who may file each subject is
 * CorrectionSubject::mayBeFiledBy(), asked first thing in request() and
 * nowhere else relied on. The Issuance and Billing subjects keep their values
 * in canonical form (CorrectionValues) so that a stale record is told apart
 * from one that merely prints differently.
 *
 * Lock order. Filing and approval lock the target the way the code that
 * writes it does, so a correction and an ordinary write take turns:
 *  - donation subjects: the donation;
 *  - unit details: the unit;
 *  - dispatch: the request, then the allocation (as release() and
 *    confirmReceipt() take them);
 *  - payment: the request, then the statement, then the payment (see the
 *    locking note on BillingService).
 * Approval locks the correction row first and the target after it. Filing
 * never locks a correction row, and nothing locks a target and then a
 * correction, so the two cannot wait on each other.
 */
class CorrectionService
{
    /**
     * What every correction is loaded with, so formatting one never queries per row.
     *
     * @var array<int, string>
     */
    private const WITH = [
        'requester',
        'reviewer',
        'donation.collection',
        'bloodUnit',
        'allocation.request',
        'payment.billing.request',
    ];

    public function __construct(
        private readonly CollectionService $collectionService,
        private readonly LaboratoryService $laboratoryService,
        private readonly InventoryService $inventoryService,
        private readonly FulfillmentService $fulfillmentService,
        private readonly BillingService $billingService,
        private readonly ClearanceRepository $clearanceRepository,
        private readonly InventoryRepository $inventoryRepository,
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
            ->with(self::WITH)
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
     * @param  int|string  $targetId  A donation, unit, allocation or payment key, as the subject's target says.
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function request(User $requester, int|string $targetId, CorrectionSubject $subject, array $changes, string $reason): array
    {
        $facility = $this->requireFacility($requester);

        // The enforcement point for who may file what. It runs before anything
        // is looked up, so a role that may not file this subject learns
        // nothing about whether the record exists.
        if (! $subject->mayBeFiledBy($requester)) {
            throw $this->refuse(403, 'not_your_record', $subject->filingRoles() === null
                ? "Your role does not record the {$subject->label()}, so it cannot correct it."
                : "Your role does not file corrections to the {$subject->label()}.");
        }

        $target = $subject->target();

        $correction = DB::transaction(function () use ($requester, $facility, $targetId, $subject, $target, $changes, $reason): CorrectionRequest {
            $locked = $this->lockTarget($subject, $targetId, $facility);
            $record = $this->primary($locked);

            $previous = $this->snapshot($locked, $subject)
                ?? throw $this->refuse(409, 'nothing_to_correct', $this->nothingToCorrect($subject));

            $this->guardCorrectable($locked, $subject);

            $pending = CorrectionRequest::query()
                ->where($target->column(), $record->getKey())
                ->where('subject', $subject->value)
                ->where('status', 'pending')
                ->exists();

            if ($pending) {
                throw $this->refuse(409, 'correction_pending', "A correction to this {$subject->label()} is already waiting for a decision.");
            }

            $validated = $this->validateChanges($subject, $changes, $requester, $record->getKey());

            $this->guardChanges($locked, $subject, $validated);

            return CorrectionRequest::create([
                'facility_id' => $facility->id,
                $target->column() => $record->getKey(),
                'subject' => $subject,
                'requested_by' => $requester->id,
                'reason' => trim($reason),
                'changes' => $validated,
                'previous' => $previous,
            ]);
        });

        $this->auditLogger->record($requester, 'correction.requested', $correction, [
            'facility_id' => $facility->id,
            $target->column() => $correction->targetKey(),
            'subject' => $subject->value,
            'fields' => $this->changedFields($correction),
        ]);

        return [
            'message' => 'Correction requested. It is applied once it is approved.',
            'data' => $this->format($correction->fresh(self::WITH), $requester),
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

        [$correction, $applied] = DB::transaction(function () use ($approver, $facility, $correctionId, $note): array {
            $correction = $this->lockPending($correctionId, $facility);
            $this->guardMayDecide($approver, $correction);

            $requester = $correction->requester()->firstOrFail();

            $applied = $correction->subject->target() === CorrectionTarget::Donation
                ? $this->applyDonationCorrection($approver, $requester, $correction)
                : $this->applyRecordCorrection($requester, $correction, $facility);

            $correction->status = 'approved';
            $correction->reviewed_by = $approver->id;
            $correction->reviewed_at = now();
            $correction->review_note = $note === null ? null : trim($note);
            $correction->save();

            return [$correction, $applied];
        });

        $context = [
            'facility_id' => $facility->id,
            $correction->subject->target()->column() => $correction->targetKey(),
            'subject' => $correction->subject->value,
        ];

        $this->auditLogger->record($approver, 'correction.approved', $correction, $context);

        // Field names only. A serology correction's values are marker readings,
        // and the audit trail is read far more widely than the laboratory.
        $this->auditLogger->record($approver, 'correction.applied', $applied, [
            ...$context,
            'correction_id' => $correction->id,
            'requested_by' => $correction->requested_by,
            'fields' => $this->changedFields($correction),
        ]);

        return [
            'message' => 'Correction approved and applied.',
            'data' => $this->format($correction->fresh(self::WITH), $approver),
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
            $correction->subject->target()->column() => $correction->targetKey(),
            'subject' => $correction->subject->value,
        ]);

        return [
            'message' => 'Correction rejected. The record is unchanged.',
            'data' => $this->format($correction->fresh(self::WITH), $approver),
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

        if ($user->staff_role !== $approverRole) {
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
                'This correction is decided by the '.$correction->subject->department()->correctionApprover()->label()
                .' of '.$correction->subject->department()->label().', or by the Center Admin.'
            );
        }
    }

    /**
     * Apply a donation-record correction through the write that recorded it.
     *
     * @return Model The donation, which the applied entry is audited against.
     */
    private function applyDonationCorrection(User $approver, User $requester, CorrectionRequest $correction): Model
    {
        $changes = $correction->changes;
        $donationId = $correction->donation_id;

        // A collection correction filed before the segment number became
        // the donation barcode still carries the old key.
        if (is_array($changes) && array_key_exists('segment_number', $changes) && ! array_key_exists('donation_barcode', $changes)) {
            $changes['donation_barcode'] = $changes['segment_number'];
            unset($changes['segment_number']);
        }

        // Re-judged now, not as it stood when the request was filed: a bag
        // may have left quarantine in between.
        $donation = Donation::query()->whereKey($donationId)->lockForUpdate()->firstOrFail();
        $this->guardCorrectable([$donation], $correction->subject);

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

        return $donation;
    }

    /**
     * Apply an Issuance or Billing correction to the record it names.
     *
     * Everything is judged again under the locks, as it stands now: the record
     * may have been edited directly, dispatched, received or voided since the
     * request was filed. A field someone else has changed since is not
     * overwritten with a value chosen against the old one.
     *
     * @return Model The record corrected, which the applied entry is audited against.
     */
    private function applyRecordCorrection(User $requester, CorrectionRequest $correction, Facility $facility): Model
    {
        $subject = $correction->subject;

        $locked = $this->lockTarget($subject, $correction->targetKey(), $facility);
        $record = $this->primary($locked);

        $this->guardCorrectable($locked, $subject);

        $current = $this->snapshot($locked, $subject)
            ?? throw $this->refuse(409, 'nothing_to_correct', $this->nothingToCorrect($subject));

        $this->assertUnchangedSince($correction, $current);

        // The values were checked when the request was filed; a date that has
        // passed since, or a reference another payment has taken, fails here.
        $changes = $this->validateChanges($subject, $correction->changes, $requester, $record->getKey());

        $this->guardChanges($locked, $subject, $changes);

        match ($subject) {
            CorrectionSubject::UnitDetails => $this->inventoryService->update($requester, (string) $record->getKey(), $changes),
            CorrectionSubject::Dispatch => $this->fulfillmentService->correctDispatch($requester, $locked[0], $locked[1], $changes),
            CorrectionSubject::Payment => $this->billingService->correctPayment($requester, $locked[0], $locked[1], $locked[2], $changes),
        };

        return $record;
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
     * Lock the record a correction is about, and what must be held with it.
     *
     * Returned in the order the locks were taken; the last is the record
     * itself. Another facility's record is a 404, the same as a missing one.
     *
     * @return array<int, Model>
     */
    private function lockTarget(CorrectionSubject $subject, int|string $targetId, Facility $facility): array
    {
        $target = $subject->target();

        return match ($target) {
            CorrectionTarget::Donation => [
                Donation::query()
                    ->where('facility_id', $facility->id)
                    ->whereKey($targetId)
                    ->lockForUpdate()
                    ->first()
                    ?? throw $this->notFound($target),
            ],

            CorrectionTarget::BloodUnit => [
                $this->inventoryRepository->lockUnit((string) $targetId, $facility->id)
                    ?? throw $this->notFound($target),
            ],

            CorrectionTarget::Allocation => $this->lockAllocation((int) $targetId, $facility),

            CorrectionTarget::Payment => $this->lockPayment((int) $targetId, $facility),
        };
    }

    /**
     * The request, then the allocation: the order release() takes them.
     *
     * @return array{0: BloodRequest, 1: RequestAllocation}
     */
    private function lockAllocation(int $allocationId, Facility $facility): array
    {
        $target = CorrectionTarget::Allocation;

        // Read without a lock only to learn which request to lock first.
        $requestId = RequestAllocation::query()->whereKey($allocationId)->value('request_id');

        $request = $requestId === null ? null : BloodRequest::query()
            ->addressedTo($facility->id)
            ->whereKey($requestId)
            ->lockForUpdate()
            ->first();

        $allocation = $request === null ? null : RequestAllocation::query()
            ->whereKey($allocationId)
            ->where('request_id', $request->id)
            ->lockForUpdate()
            ->first();

        if ($request === null || $allocation === null) {
            throw $this->notFound($target);
        }

        return [$request, $allocation];
    }

    /**
     * The request, then the statement, then the payment: the billing lock order.
     *
     * @return array{0: BloodRequest, 1: Billing, 2: Payment}
     */
    private function lockPayment(int $paymentId, Facility $facility): array
    {
        $target = CorrectionTarget::Payment;

        $requestId = Payment::query()
            ->join('billings', 'billings.id', '=', 'payments.billing_id')
            ->where('payments.id', $paymentId)
            ->value('billings.request_id');

        // Checked here so another facility's payment is a payment_not_found,
        // as every other target's is, and not the billing service's own 404.
        if ($requestId === null || ! BloodRequest::query()->addressedTo($facility->id)->whereKey($requestId)->exists()) {
            throw $this->notFound($target);
        }

        [$request, $billing] = $this->billingService->lockForMutation((int) $requestId, $facility->id);

        $payment = Payment::query()
            ->whereKey($paymentId)
            ->where('billing_id', $billing->id)
            ->lockForUpdate()
            ->first()
            ?? throw $this->notFound($target);

        return [$request, $billing, $payment];
    }

    /**
     * The record itself, which is the last thing locked.
     *
     * @param  array<int, Model>  $locked
     */
    private function primary(array $locked): Model
    {
        return $locked[array_key_last($locked)];
    }

    /**
     * Refuse a correction to a record that can no longer change.
     *
     * @param  array<int, Model>  $locked
     */
    private function guardCorrectable(array $locked, CorrectionSubject $subject): void
    {
        $record = $this->primary($locked);

        if ($subject === CorrectionSubject::Dispatch) {
            return;
        }

        if ($subject === CorrectionSubject::Payment) {
            if ($locked[1]->status === BillingStatus::Void) {
                throw $this->refuse(409, 'billing_void', 'This statement has been voided, so its payments can no longer be corrected.');
            }

            return;
        }

        if ($subject->target() !== CorrectionTarget::Donation) {
            return;
        }

        /** @var Donation $donation */
        $donation = $record;

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
     * Refuse values the record's state cannot take, once they have been validated.
     *
     * A unit's edit rule depends on which fields are sent, so it can only be
     * judged after validation: it is the very rule a direct edit is held to.
     *
     * @param  array<int, Model>  $locked
     * @param  array<string, mixed>  $validated
     */
    private function guardChanges(array $locked, CorrectionSubject $subject, array $validated): void
    {
        if ($subject === CorrectionSubject::UnitDetails) {
            $this->inventoryService->guardEditable($locked[0], $validated);
        }
    }

    /**
     * What the record says now, or null when there is nothing recorded to correct.
     *
     * The Issuance and Billing subjects come back in canonical form.
     *
     * @param  array<int, Model>  $locked
     * @return array<string, mixed>|null
     */
    private function snapshot(array $locked, CorrectionSubject $subject): ?array
    {
        $record = $this->primary($locked);

        $snapshot = match ($subject) {
            CorrectionSubject::Screening => DonationScreening::query()->where('donation_id', $record->id)->first()?->only([
                'outcome', 'deferral_reason', 'systolic_bp', 'diastolic_bp', 'pulse_bpm', 'temperature_c',
                'weight_kg', 'haemoglobin_g_dl', 'fingerprick_blood_type_id', 'sleep', 'meal', 'meds', 'allergies',
                'general_appearance', 'skin', 'heent', 'heart_and_lungs', 'notes',
            ]),
            CorrectionSubject::Collection => ($collection = BloodCollection::query()->where('donation_id', $record->id)->first()) === null ? null : [
                'blood_bag_type' => $collection->blood_bag_type?->value,
                'donation_barcode' => $collection->donation_barcode,
                'started_at' => $collection->started_at?->toISOString(),
                'ended_at' => $collection->ended_at?->toISOString(),
                'volume_ml' => $record->volume_ml,
            ],
            CorrectionSubject::Immunohematology => DonationImmunohematology::query()->where('donation_id', $record->id)->first()?->only([
                'blood_type_id', 'forward_group', 'reverse_group', 'antibody_screen', 'notes',
            ]),
            CorrectionSubject::Serology => DonationSerology::query()->where('donation_id', $record->id)->first()?->only([
                'hiv', 'hbsag', 'hcv', 'syphilis', 'malaria',
            ]),
            CorrectionSubject::Components => ($rows = DonationComponent::query()->where('donation_id', $record->id)->orderBy('id')->get())->isEmpty()
                ? null
                : ['components' => $rows->map(fn (DonationComponent $row): array => [
                    'component_id' => $row->component_id,
                    'volume_ml' => $row->volume_ml,
                ])->all()],
            CorrectionSubject::UnitDetails => [
                'storage_location' => $record->storage_location,
                'expiry_date' => $record->expiry_date?->toDateString(),
            ],
            // Only a dispatched unit has a dispatch record to correct.
            CorrectionSubject::Dispatch => $record->status === AllocationStatus::Released ? [
                'released_at' => $record->released_at?->toIso8601String(),
                'handed_to' => $record->handed_to,
            ] : null,
            CorrectionSubject::Payment => [
                'amount_paid' => $record->amount_paid,
                'payment_method' => $record->payment_method?->value,
                'reference_number' => $record->reference_number,
            ],
        };

        return $snapshot === null || $subject->fieldTypes() === []
            ? $snapshot
            : CorrectionValues::normalizeAll($snapshot, $subject->fieldTypes());
    }

    /**
     * Refuse approval of a correction whose record has moved since it was filed.
     *
     * Only the fields the correction changes are compared, in canonical form,
     * so an edit to some other field does not block it and a value that merely
     * prints differently does not look like a change.
     *
     * @param  array<string, mixed>  $current
     */
    private function assertUnchangedSince(CorrectionRequest $correction, array $current): void
    {
        $types = $correction->subject->fieldTypes();
        $previous = $correction->previous ?? [];

        foreach (array_keys($correction->changes) as $field) {
            $type = $types[$field] ?? null;

            if ($type === null) {
                continue;
            }

            if (! CorrectionValues::same($previous[$field] ?? null, $current[$field] ?? null, $type)) {
                throw $this->refuse(
                    409,
                    'record_changed',
                    "This {$correction->subject->label()} has changed since the correction was filed. Reject it and file the correction again."
                );
            }
        }
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
    private function validateChanges(CorrectionSubject $subject, array $changes, User $requester, int|string $targetKey): array
    {
        $class = $subject->requestClass();

        /** @var FormRequest $form */
        $form = $class::create('/', 'POST', $changes);
        $form->setContainer(app())->setRedirector(app(Redirector::class));
        $form->setUserResolver(fn (): User => $requester);

        // Lets the request ignore, or read, the record being corrected: a
        // barcode or reference that must stay unique, an allocation's times.
        $property = $subject->target()->correctingProperty();

        if (property_exists($form, $property)) {
            $form->{$property} = is_numeric($targetKey) ? (int) $targetKey : $targetKey;
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

        $validated = $form->validated();

        return $subject->fieldTypes() === []
            ? $validated
            : CorrectionValues::normalizeAll($validated, $subject->fieldTypes());
    }

    /**
     * The names of the fields a correction changes.
     *
     * @return array<int, string>
     */
    private function changedFields(CorrectionRequest $correction): array
    {
        $types = $correction->subject->fieldTypes();
        $previous = $correction->previous ?? [];
        $fields = [];

        foreach ($correction->changes as $field => $value) {
            $before = $previous[$field] ?? null;

            $changed = isset($types[$field])
                ? ! CorrectionValues::same($before, $value, $types[$field])
                : $before != $value;

            if ($changed) {
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
        $subject = $correction->subject;

        return [
            'id' => $correction->id,
            'donation_id' => $correction->donation_id,
            // Barcode, not name: the approver may be a blind laboratory role.
            'donation_barcode' => $correction->donation?->collection?->donation_barcode,
            'target_type' => $subject->target()->value,
            'target_id' => $correction->targetKey(),
            'blood_unit_id' => $correction->blood_unit_id,
            'request_allocation_id' => $correction->request_allocation_id,
            'payment_id' => $correction->payment_id,
            'target_label' => $this->targetLabel($correction),
            'subject' => $subject->value,
            'subject_label' => $subject->label(),
            'department' => $subject->department()->value,
            'approver_label' => $subject->department()->correctionApprover()->label(),
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

    /**
     * What the correction is about, in words an approver can place.
     *
     * Never a donor's name or a payment reference: the approver may be a role
     * that is not meant to read either.
     */
    private function targetLabel(CorrectionRequest $correction): string
    {
        return match ($correction->subject->target()) {
            CorrectionTarget::Donation => 'Donation #'.$correction->donation_id,
            CorrectionTarget::BloodUnit => 'Unit '.$correction->blood_unit_id,
            CorrectionTarget::Allocation => trim('Unit '.$correction->allocation?->unit_id
                .($correction->allocation?->request?->reference_number ? ' · '.$correction->allocation->request->reference_number : '')),
            CorrectionTarget::Payment => trim('Payment #'.$correction->payment_id
                .($correction->payment?->billing?->request?->reference_number ? ' · '.$correction->payment->billing->request->reference_number : '')),
        };
    }

    private function name(?User $user): ?string
    {
        return $user === null ? null : trim($user->first_name.' '.$user->last_name);
    }

    private function nothingToCorrect(CorrectionSubject $subject): string
    {
        return $subject->target() === CorrectionTarget::Donation
            ? "No {$subject->label()} is recorded for this donation yet — record it directly."
            : "There is no {$subject->label()} to correct here yet.";
    }

    private function notFound(CorrectionTarget $target): HttpResponseException
    {
        $refusal = $target->notFound();

        return $this->refuse(404, $refusal['code'], $refusal['message']);
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
