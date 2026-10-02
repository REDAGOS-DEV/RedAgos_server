<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\HospitalUnitStatus;
use App\Enums\UnitTagStatus;
use App\Enums\UntagReason;
use App\Models\BloodUnit;
use App\Models\HospitalUnit;
use App\Models\RequestAllocation;
use App\Models\TransfusionRequest;
use App\Models\UnitTag;
use App\Models\User;
use App\Repository\HospitalInventoryRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The rules for a hospital blood bank's own stock: receipt, tagging, crossmatch, transfusion.
 *
 * A tag is a specific physical bag held for a specific patient — never a count
 * of a blood type. Tag Assigned keeps the bag in storage, unavailable to
 * anybody else, for 24 hours while crossmatching is done; Tag Crossmatched
 * means it has left storage for that patient, with 24 hours to transfuse.
 * Either period running out untags the bag (the hospital:expire-tags
 * command's job), and staff may release a tag sooner with a reason. See
 * docs/hospital-blood-bank-inventory-rules.md.
 *
 * Every write takes the bag's row lock, then its active tag's, re-checks the
 * transition and the deadline under them, and audits. A write that arrives at
 * or after a deadline is refused rather than allowed to untag inline — the
 * refusal rolls back the transaction anyway, and keeping the scheduler as the
 * only system writer means every automatic untag carries its run.
 *
 * Nothing here writes to blood_units. The bag's facts, its expiry date among
 * them, belong to the centre that collected it and are only ever read.
 */
class HospitalInventoryService
{
    /**
     * How long each tag stage lasts. Fixed by the specification, not configured.
     */
    private const DEADLINE_HOURS = 24;

    /**
     * The patient columns a tag carries, as the request may supply them.
     *
     * @var array<int, string>
     */
    private const PATIENT_FIELDS = [
        'patient_surname',
        'patient_first_name',
        'patient_middle_name',
        'patient_age',
        'patient_sex',
        'patient_record_number',
        'patient_ward',
        'attending_physician',
        'patient_blood_type_id',
    ];

    public function __construct(
        private readonly HospitalInventoryRepository $repository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * List the caller's hospital stock, FEFO-ordered.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $facilityId = $this->requireFacilityId($user);
        $filters['operational_date'] = OperationalDay::todayAsDate();
        $now = now()->toImmutable();

        return $this->repository
            ->paginateUnits($facilityId, $filters, $perPage)
            ->through(fn (HospitalUnit $unit): array => $this->format($unit, $now));
    }

    /**
     * Summarise the caller's hospital stock, every number derived from its bags.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $facilityId = $this->requireFacilityId($user);
        $today = OperationalDay::todayAsDate();
        $now = now()->toImmutable();

        return [
            'totals' => $this->repository->summaryCounts($facilityId),
            'by_blood_type' => $this->repository->countsByBloodType($facilityId),
            'near_expiry' => [
                'within_3_days' => $this->repository->expiringWithinCount($facilityId, $today, 3),
            ],
            'overdue_active_tags' => $this->repository->overdueActiveTagCount($facilityId, $now),
            'as_of' => $now->toIso8601String(),
        ];
    }

    /**
     * Show one bag with every tag ever placed on it.
     *
     * @return array<string, mixed>
     */
    public function show(User $user, string $unitId): array
    {
        $facilityId = $this->requireFacilityId($user);
        $unit = $this->repository->findUnitForFacility($unitId, $facilityId)
            ?? throw $this->refuse(404, 'unit_not_found', 'This blood unit is not in your blood bank.');

        $now = now()->toImmutable();

        return [
            'unit' => $this->format($unit, $now),
            'tags' => $this->repository->tagHistory($unit->id)
                ->map(fn (UnitTag $tag): array => $this->formatTag($tag, $now, withActors: true))
                ->all(),
            'as_of' => $now->toIso8601String(),
        ];
    }

    /**
     * List the tags that ended at the caller's hospital, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function tagEvents(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $facilityId = $this->requireFacilityId($user);
        $now = now()->toImmutable();

        return $this->repository
            ->paginateTagEvents($facilityId, $filters, $perPage)
            ->through(fn (UnitTag $tag): array => [
                ...$this->formatTag($tag, $now, withActors: true),
                'unit' => [
                    'unit_id' => $tag->hospitalUnit?->unit_id,
                    'status' => $tag->hospitalUnit?->status->value,
                    'blood_type' => $tag->hospitalUnit?->bloodUnit?->bloodType?->code,
                    'component' => $tag->hospitalUnit?->bloodUnit?->component?->name,
                ],
            ]);
    }

    /**
     * Put the bags a hospital just confirmed receipt of into its custody.
     *
     * Called by FulfillmentService::confirmReceipt() inside its transaction,
     * after the holds are stamped received. Only inserts, so it takes no lock
     * and leaves receipt's lock order (request, then allocations, then the
     * requirement) as it was. Receipt is the only way stock reaches a hospital:
     * nothing received before this existed is backfilled.
     *
     * @param  Collection<int, RequestAllocation>  $allocations
     */
    public function stockReceived(User $user, int $facilityId, Collection $allocations): int
    {
        if ($allocations->isEmpty()) {
            return 0;
        }

        $units = $this->repository->createUnits(
            $facilityId,
            $allocations->map(fn (RequestAllocation $allocation): array => [
                'unit_id' => $allocation->unit_id,
                'request_allocation_id' => $allocation->id,
            ])->values()->all()
        );

        $bags = BloodUnit::query()->whereIn('id', $units->pluck('unit_id'))->get()->keyBy('id');

        foreach ($units as $unit) {
            $this->auditLogger->record($user, 'hospital_inventory.stocked', $bags->get($unit->unit_id), [
                'facility_id' => $facilityId,
                'hospital_unit_id' => $unit->id,
                'request_allocation_id' => $unit->request_allocation_id,
                'new_status' => HospitalUnitStatus::Available->value,
            ]);
        }

        return $units->count();
    }

    /**
     * Tag an available bag to a patient: Tag Assigned, with 24 hours to crossmatch.
     *
     * The patient is entered on the tag. A Patient Transfusion Request of the
     * same hospital may be named instead or as well, and fills in whatever
     * patient detail the request leaves out.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function tag(User $user, string $unitId, array $payload): array
    {
        $facilityId = $this->requireFacilityId($user);
        $now = now()->startOfSecond()->toImmutable();

        $unit = DB::transaction(function () use ($user, $unitId, $facilityId, $payload, $now): HospitalUnit {
            $unit = $this->lockUnit($unitId, $facilityId);

            if ($unit->status !== HospitalUnitStatus::Available) {
                throw $this->refuse(409, 'unit_not_available', $this->notAvailableMessage($unit->status));
            }

            $this->guardBagInDate($unit);

            $requirement = $this->linkedRequirement($payload, $facilityId);

            $tag = UnitTag::query()->create([
                ...$this->patientFor($payload, $requirement),
                'hospital_unit_id' => $unit->id,
                'facility_id' => $facilityId,
                'transfusion_request_id' => $requirement?->id,
                'status' => UnitTagStatus::TagAssigned,
                'tagged_at' => $now,
                'tagged_by' => $user->id,
                'crossmatch_deadline_at' => $now->addHours(self::DEADLINE_HOURS),
            ]);

            $this->advance($unit, HospitalUnitStatus::TagAssigned);

            $this->audit($user, 'hospital_inventory.tagged', $unit, $tag, HospitalUnitStatus::Available, [
                'deadline_at' => $tag->crossmatch_deadline_at->toIso8601String(),
            ]);

            return $unit;
        });

        return $this->respond('Blood unit tagged to the patient.', $unit, $facilityId);
    }

    /**
     * Record that crossmatching was completed: the same bag becomes Tag Crossmatched.
     *
     * The bag leaves storage for the patient, and the transfusion's 24 hours
     * start now. Refused at or after the crossmatch deadline: by then the tag
     * has lapsed, whether or not the sweep has reached it yet.
     *
     * @return array<string, mixed>
     */
    public function crossmatch(User $user, string $unitId): array
    {
        $facilityId = $this->requireFacilityId($user);
        $now = now()->startOfSecond()->toImmutable();

        $unit = DB::transaction(function () use ($user, $unitId, $facilityId, $now): HospitalUnit {
            $unit = $this->lockUnit($unitId, $facilityId);
            $tag = $this->repository->lockActiveTag($unit->id);

            if ($unit->status !== HospitalUnitStatus::TagAssigned || $tag?->status !== UnitTagStatus::TagAssigned) {
                throw $this->refuse(409, 'invalid_transition', match ($unit->status) {
                    HospitalUnitStatus::Available => 'This bag is not tagged to a patient. Tag it before recording a crossmatch.',
                    HospitalUnitStatus::TagCrossmatched => 'This bag has already been crossmatched.',
                    default => "A bag that is {$unit->status->label()} cannot be crossmatched.",
                });
            }

            $this->guardDeadline($tag->crossmatch_deadline_at, $now, 'crossmatch');
            $this->guardBagInDate($unit);

            $tag->status = UnitTagStatus::TagCrossmatched;
            $tag->crossmatched_at = $now;
            $tag->crossmatched_by = $user->id;
            $tag->transfusion_deadline_at = $now->addHours(self::DEADLINE_HOURS);
            $tag->save();

            $this->advance($unit, HospitalUnitStatus::TagCrossmatched);

            $this->audit($user, 'hospital_inventory.crossmatched', $unit, $tag, HospitalUnitStatus::TagAssigned, [
                'crossmatch_deadline_at' => $tag->crossmatch_deadline_at->toIso8601String(),
                'deadline_at' => $tag->transfusion_deadline_at->toIso8601String(),
            ]);

            return $unit;
        });

        return $this->respond('Crossmatch recorded. The bag is awaiting transfusion.', $unit, $facilityId);
    }

    /**
     * Record the transfusion. The bag is used and can never return to stock.
     *
     * Only a crossmatched bag may be transfused: Tag Assigned straight to
     * Transfused would skip the crossmatch the patient's safety rests on.
     *
     * @return array<string, mixed>
     */
    public function transfuse(User $user, string $unitId): array
    {
        $facilityId = $this->requireFacilityId($user);
        $now = now()->startOfSecond()->toImmutable();

        $unit = DB::transaction(function () use ($user, $unitId, $facilityId, $now): HospitalUnit {
            $unit = $this->lockUnit($unitId, $facilityId);
            $tag = $this->repository->lockActiveTag($unit->id);

            if ($unit->status !== HospitalUnitStatus::TagCrossmatched || $tag?->status !== UnitTagStatus::TagCrossmatched) {
                throw $this->refuse(409, 'invalid_transition', match ($unit->status) {
                    HospitalUnitStatus::TagAssigned => 'This bag has not been crossmatched. Record the crossmatch before the transfusion.',
                    HospitalUnitStatus::Available => 'This bag is not tagged to a patient.',
                    HospitalUnitStatus::Transfused => 'This bag has already been transfused.',
                    default => "A bag that is {$unit->status->label()} cannot be transfused.",
                });
            }

            $this->guardDeadline($tag->transfusion_deadline_at, $now, 'transfusion');
            $this->guardBagInDate($unit);

            $tag->status = UnitTagStatus::Transfused;
            $tag->transfused_at = $now;
            $tag->transfused_by = $user->id;
            $tag->save();

            $this->advance($unit, HospitalUnitStatus::Transfused);

            $this->audit($user, 'hospital_inventory.transfused', $unit, $tag, HospitalUnitStatus::TagCrossmatched, [
                'crossmatched_at' => $tag->crossmatched_at?->toIso8601String(),
                'deadline_at' => $tag->transfusion_deadline_at?->toIso8601String(),
            ]);

            return $unit;
        });

        return $this->respond('Transfusion recorded.', $unit, $facilityId);
    }

    /**
     * Release a bag's active tag before its deadline, for the reason staff give.
     *
     * A Tag Assigned bag never left storage, so it is available again at once.
     * A crossmatched one has, so it waits as Pending Return until staff confirm
     * it is back in storage or discard it. Either way the tag ends as Untagged
     * Assigned or Untagged Crossmatched and stays as history.
     *
     * @return array<string, mixed>
     */
    public function release(User $user, string $unitId, string $reason): array
    {
        $facilityId = $this->requireFacilityId($user);
        $now = now()->startOfSecond()->toImmutable();

        $unit = DB::transaction(function () use ($user, $unitId, $facilityId, $reason, $now): HospitalUnit {
            $unit = $this->lockUnit($unitId, $facilityId);
            $tag = $this->repository->lockActiveTag($unit->id);

            if ($tag === null) {
                throw $this->refuse(409, 'invalid_transition', 'This bag is not tagged to a patient, so there is nothing to release.');
            }

            $from = $unit->status;
            $wasCrossmatched = $tag->status === UnitTagStatus::TagCrossmatched;
            $deadline = $tag->activeDeadline();

            $tag->status = $tag->status->untaggedState();
            $tag->untagged_at = $now;
            $tag->untagged_by = $user->id;
            $tag->untag_reason = UntagReason::ReleasedByStaff;
            $tag->untag_note = $reason;
            $tag->save();

            $this->advance($unit, $wasCrossmatched ? HospitalUnitStatus::PendingReturn : HospitalUnitStatus::Available);

            $this->audit($user, 'hospital_inventory.untagged', $unit, $tag, $from, [
                'tag_status' => $tag->status->value,
                'deadline_at' => $deadline?->toIso8601String(),
                'untag_reason' => UntagReason::ReleasedByStaff->value,
                'note' => $reason,
            ]);

            return $unit;
        });

        return $this->respond(
            $unit->status === HospitalUnitStatus::PendingReturn
                ? 'Tag released. Confirm the bag is back in storage, or discard it.'
                : 'Tag released. The bag is available again.',
            $unit,
            $facilityId
        );
    }

    /**
     * Confirm a bag that came back from an Untagged Crossmatched tag is in storage again.
     *
     * @return array<string, mixed>
     */
    public function confirmReturn(User $user, string $unitId): array
    {
        $facilityId = $this->requireFacilityId($user);
        $now = now()->startOfSecond()->toImmutable();

        $unit = DB::transaction(function () use ($user, $unitId, $facilityId, $now): HospitalUnit {
            $unit = $this->lockUnit($unitId, $facilityId);

            if ($unit->status !== HospitalUnitStatus::PendingReturn) {
                throw $this->refuse(409, 'invalid_transition', 'Only a bag awaiting return to storage can be returned.');
            }

            $tag = $this->repository->lockLatestTag($unit->id);

            if ($tag?->status !== UnitTagStatus::UntaggedCrossmatched || $tag->returned_at !== null) {
                throw new LogicException("Hospital unit {$unit->id} is pending return without an open Untagged Crossmatched tag.");
            }

            $tag->returned_at = $now;
            $tag->returned_by = $user->id;
            $tag->save();

            $this->advance($unit, HospitalUnitStatus::Available);

            $this->audit($user, 'hospital_inventory.returned', $unit, $tag, HospitalUnitStatus::PendingReturn);

            return $unit;
        });

        return $this->respond('The bag is back in storage and available.', $unit, $facilityId);
    }

    /**
     * Record that a bag has left the hospital's shelf for disposal.
     *
     * Available, expired and pending-return bags may be discarded. A tagged
     * bag is promised to a patient and must be released first.
     *
     * @return array<string, mixed>
     */
    public function discard(User $user, string $unitId, string $reason): array
    {
        $facilityId = $this->requireFacilityId($user);
        $now = now()->startOfSecond()->toImmutable();

        $unit = DB::transaction(function () use ($user, $unitId, $facilityId, $reason, $now): HospitalUnit {
            $unit = $this->lockUnit($unitId, $facilityId);
            $from = $unit->status;

            if (! $from->isDiscardable()) {
                throw $this->refuse(409, 'unit_not_discardable', match ($from) {
                    HospitalUnitStatus::Discarded => 'This bag has already been discarded.',
                    HospitalUnitStatus::Transfused => 'A transfused bag cannot be discarded.',
                    default => 'This bag is tagged to a patient. Release the tag before discarding it.',
                });
            }

            $unit->discarded_at = $now;
            $unit->discarded_by = $user->id;
            $unit->discard_reason = $reason;
            $this->advance($unit, HospitalUnitStatus::Discarded);

            $this->audit($user, 'hospital_inventory.discarded', $unit, null, $from, [
                'discard_reason' => $reason,
            ]);

            return $unit;
        });

        return $this->respond('Blood unit discarded.', $unit, $facilityId);
    }

    /**
     * Lock one of the hospital's bags, or refuse as if it did not exist.
     */
    private function lockUnit(string $unitId, int $facilityId): HospitalUnit
    {
        return $this->repository->lockUnitForFacility($unitId, $facilityId)
            ?? throw $this->refuse(404, 'unit_not_found', 'This blood unit is not in your blood bank.');
    }

    /**
     * Move a locked bag to its next status and save it.
     *
     * The transition map is the backstop behind each caller's own check: a
     * move it does not allow is a bug here, not a staff mistake, so it is a
     * LogicException rather than a refusal.
     */
    private function advance(HospitalUnit $unit, HospitalUnitStatus $next): void
    {
        if (! $unit->status->canTransitionTo($next)) {
            throw new LogicException("Hospital unit {$unit->id} cannot move from {$unit->status->value} to {$next->value}.");
        }

        $unit->status = $next;
        $unit->save();
    }

    /**
     * Refuse a write that arrives at or after the tag's deadline.
     */
    private function guardDeadline(?CarbonImmutable $deadline, CarbonImmutable $now, string $stage): void
    {
        if ($deadline !== null && $now->greaterThanOrEqualTo($deadline)) {
            throw $this->refuse(
                409,
                'tag_deadline_passed',
                "The 24-hour {$stage} period for this tag ended at {$deadline->toDayDateTimeString()}. The bag is being released from the patient."
            );
        }
    }

    /**
     * Refuse to tag, crossmatch or transfuse a bag past its expiry date.
     *
     * A bag expiring today may still be used until the operational day ends,
     * the same rule the centre's allocation applies.
     */
    private function guardBagInDate(HospitalUnit $unit): void
    {
        $expiry = $unit->bloodUnit()->value('expiry_date');

        if ($expiry !== null && CarbonImmutable::parse($expiry)->toDateString() < OperationalDay::todayAsDate()) {
            throw $this->refuse(409, 'bag_expired', 'This bag is past its expiry date and cannot be issued to a patient.');
        }
    }

    /**
     * Resolve the Patient Transfusion Request a tag names, if any.
     *
     * @param  array<string, mixed>  $payload
     */
    private function linkedRequirement(array $payload, int $facilityId): ?TransfusionRequest
    {
        if (! isset($payload['transfusion_request_id'])) {
            return null;
        }

        $requirement = $this->repository->findTransfusionRequestFor((int) $payload['transfusion_request_id'], $facilityId)
            ?? throw $this->refuse(404, 'transfusion_request_not_found', 'That Patient Transfusion Request was not found.');

        if ($requirement->status === BloodRequestStatus::Cancelled) {
            throw $this->refuse(409, 'transfusion_request_closed', 'That Patient Transfusion Request was cancelled. Tag the bag to the patient directly instead.');
        }

        return $requirement;
    }

    /**
     * The patient a tag names: what staff entered, filled in from the linked requirement.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function patientFor(array $payload, ?TransfusionRequest $requirement): array
    {
        $fromRequirement = $requirement === null ? [] : [
            'patient_surname' => $requirement->patient_surname,
            'patient_first_name' => $requirement->patient_first_name,
            'patient_middle_name' => $requirement->patient_middle_name,
            'patient_age' => $requirement->patient_age,
            'patient_sex' => $requirement->patient_sex,
            'patient_blood_type_id' => $requirement->blood_type_id,
        ];

        $patient = [];

        foreach (self::PATIENT_FIELDS as $field) {
            $patient[$field] = $payload[$field] ?? $fromRequirement[$field] ?? null;
        }

        // A requirement moved in from before patients were mandatory may not
        // name one; the tag must, so staff are asked rather than guessed for.
        $missing = array_filter(
            ['patient_surname', 'patient_first_name', 'patient_age', 'patient_sex'],
            fn (string $field): bool => $patient[$field] === null || $patient[$field] === ''
        );

        if ($missing !== []) {
            throw ValidationException::withMessages(array_fill_keys(
                array_values($missing),
                'The linked request does not record this; enter it for the patient.'
            ));
        }

        return $patient;
    }

    /**
     * Audit one transition of a hospital bag, against the bag itself.
     *
     * The subject is the centre's BloodUnit, so everything that happened to a
     * bag — at the centre and at the hospital — is one query. Patient names
     * stay out: audit_logs carries identifiers only, and the tag row is where
     * the patient lives.
     *
     * @param  array<string, mixed>  $context
     */
    private function audit(User $user, string $action, HospitalUnit $unit, ?UnitTag $tag, HospitalUnitStatus $from, array $context = []): void
    {
        $this->auditLogger->record($user, $action, $unit->bloodUnit, [
            'facility_id' => $unit->facility_id,
            'hospital_unit_id' => $unit->id,
            'tag_id' => $tag?->id,
            'transfusion_request_id' => $tag?->transfusion_request_id,
            'previous_status' => $from->value,
            'new_status' => $unit->status->value,
            ...$context,
        ]);
    }

    /**
     * The response every write returns: a message and the bag as it now stands.
     *
     * @return array<string, mixed>
     */
    private function respond(string $message, HospitalUnit $unit, int $facilityId): array
    {
        $fresh = $this->repository->findUnitForFacility($unit->unit_id, $facilityId) ?? $unit;

        return [
            'message' => $message,
            'unit' => $this->format($fresh, now()->toImmutable()),
        ];
    }

    /**
     * Explain why a bag cannot be tagged.
     */
    private function notAvailableMessage(HospitalUnitStatus $status): string
    {
        return match ($status) {
            HospitalUnitStatus::TagAssigned, HospitalUnitStatus::TagCrossmatched => 'This bag is already tagged to another patient.',
            HospitalUnitStatus::PendingReturn => 'This bag is awaiting return to storage. Confirm the return before tagging it.',
            default => "A bag that is {$status->label()} cannot be tagged.",
        };
    }

    /**
     * Project a bag for the API.
     *
     * @return array<string, mixed>
     */
    private function format(HospitalUnit $unit, CarbonImmutable $now): array
    {
        $bag = $unit->bloodUnit;
        $expiry = $bag?->expiry_date ? CarbonImmutable::parse($bag->expiry_date) : null;
        $request = $unit->requestAllocation?->request;

        return [
            'id' => $unit->id,
            'unit_id' => $unit->unit_id,
            'status' => $unit->status->value,
            'status_label' => $unit->status->label(),
            'blood_type' => [
                'id' => $bag?->blood_type_id,
                'code' => $bag?->bloodType?->code,
            ],
            'component' => [
                'id' => $bag?->component_id,
                'name' => $bag?->component?->name,
            ],
            'volume_ml' => $bag?->volume_ml,
            'expiry_date' => $expiry?->toDateString(),
            'days_remaining' => $expiry ? OperationalDay::daysUntil($expiry) : null,
            'bag_expired' => $expiry !== null && $expiry->toDateString() < OperationalDay::todayAsDate(),
            'stocked_at' => $unit->created_at?->toIso8601String(),
            'source' => [
                'request_id' => $request?->id,
                'reference_number' => $request?->reference_number,
                'transfusion_request_id' => $request?->transfusion_request_id,
                'transfusion_reference' => $request?->transfusionRequest?->reference_number,
            ],
            'active_tag' => $unit->activeTag ? $this->formatTag($unit->activeTag, $now) : null,
            // Why a bag is out of storage without a patient: the tag that ended.
            'last_tag' => $unit->status === HospitalUnitStatus::PendingReturn && $unit->latestTag
                ? $this->formatTag($unit->latestTag, $now)
                : null,
            'expired_at' => $unit->expired_at?->toIso8601String(),
            'discarded_at' => $unit->discarded_at?->toIso8601String(),
            'discard_reason' => $unit->discard_reason,
        ];
    }

    /**
     * Project a tag for the API, with the time left on an active one.
     *
     * @return array<string, mixed>
     */
    private function formatTag(UnitTag $tag, CarbonImmutable $now, bool $withActors = false): array
    {
        $deadline = $tag->activeDeadline();
        $name = fn (?User $actor): ?string => $actor === null ? null : trim($actor->first_name.' '.$actor->last_name);

        return [
            'id' => $tag->id,
            'status' => $tag->status->value,
            'status_label' => $tag->status->label(),
            'description' => $tag->status->description(),
            'patient' => [
                'full_name' => $tag->patientFullName(),
                'surname' => $tag->patient_surname,
                'first_name' => $tag->patient_first_name,
                'middle_name' => $tag->patient_middle_name,
                'age' => $tag->patient_age,
                'sex' => $tag->patient_sex,
                'record_number' => $tag->patient_record_number,
                'ward' => $tag->patient_ward,
                'attending_physician' => $tag->attending_physician,
                'blood_type' => $tag->patientBloodType?->code,
            ],
            'transfusion_request' => $tag->transfusion_request_id === null ? null : [
                'id' => $tag->transfusion_request_id,
                'reference_number' => $tag->transfusionRequest?->reference_number,
            ],
            'tagged_at' => $tag->tagged_at?->toIso8601String(),
            'crossmatch_deadline_at' => $tag->crossmatch_deadline_at?->toIso8601String(),
            'crossmatched_at' => $tag->crossmatched_at?->toIso8601String(),
            'transfusion_deadline_at' => $tag->transfusion_deadline_at?->toIso8601String(),
            'transfused_at' => $tag->transfused_at?->toIso8601String(),
            'untagged_at' => $tag->untagged_at?->toIso8601String(),
            'untag_reason' => $tag->untag_reason?->value,
            'untag_reason_label' => $tag->untag_reason?->label(),
            'untag_note' => $tag->untag_note,
            'untagged_by_system' => $tag->untagged_at !== null && $tag->untagged_by === null,
            'returned_at' => $tag->returned_at?->toIso8601String(),
            'deadline_at' => $deadline?->toIso8601String(),
            'seconds_remaining' => $deadline === null ? null : max(0, (int) $now->diffInSeconds($deadline, false)),
            'deadline_passed' => $deadline !== null && $now->greaterThanOrEqualTo($deadline),
            ...($withActors ? ['actors' => [
                'tagged_by' => $name($tag->taggedBy),
                'crossmatched_by' => $name($tag->crossmatchedBy),
                'transfused_by' => $name($tag->transfusedBy),
                'untagged_by' => $name($tag->untaggedBy),
                'returned_by' => $name($tag->returnedBy),
            ]] : []),
        ];
    }

    /**
     * The facility the caller acts for, resolved from the account and never from input.
     */
    private function requireFacilityId(User $user): int
    {
        return $user->facility_id
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
