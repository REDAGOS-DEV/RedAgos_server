<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\FacilityStatus;
use App\Enums\FacilityTypeName;
use App\Enums\RequestEventType;
use App\Enums\RequestPurpose;
use App\Enums\RequestSource;
use App\Enums\UrgencyLevel;
use App\Enums\ValidIdType;
use App\Models\BloodRequest;
use App\Models\BloodRequestWalkIn;
use App\Models\Facility;
use App\Models\TransfusionRequest;
use App\Models\User;
use App\Repository\BloodRequestRepository;
use App\Repository\TransfusionRequestRepository;
use App\Support\RequestFormReferenceData;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A Patient Transfusion request that a watcher brought straight to a blood centre.
 *
 * The watcher did not go through the hospital blood bank first, so there is no
 * portal request. Issuance staff phone the hospital blood bank and, only if the
 * hospital confirms the request, record it here on the hospital's behalf. While
 * they are on the call nothing is saved; a call the hospital does not confirm
 * leaves no record at all.
 *
 * Three things are fixed rather than taken from input. The requesting facility
 * is the patient's hospital, which must be a registered blood bank — it is the
 * institutional party, not the watcher. The target is always the caller's own
 * centre. And the purpose is always Patient Transfusion: a replenishment order
 * is the hospital's own business and only ever comes through the portal.
 *
 * The result is the same as the hospital's own portal would make: a Patient
 * Transfusion Request in the hospital's PTR sequence, with one facility
 * allocation — addressed to this centre, for the whole need — in its RQ
 * sequence. The walk-in row sits on that allocation, recording who presented
 * it and who at the hospital confirmed it. The hospital sees the requirement
 * in its own list and may ask other centres for whatever this one cannot
 * supply; and if the watcher carries the rest to another centre, that centre
 * adds its own allocation to the same requirement ("continue").
 */
class WalkInRequestService
{
    private const REFERENCE_ATTEMPTS = 3;

    public function __construct(
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly TransfusionRequestRepository $transfusionRepository,
        private readonly BloodRequestProjector $projector,
        private readonly TransfusionRequestResolver $transfusionResolver,
        private readonly TransfusionAllocationWriter $writer,
        private readonly BloodRequestHistory $history,
        private readonly BloodRequestNotifier $notifier,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Serve everything the walk-in form needs.
     *
     * @return array<string, mixed>
     */
    public function formReference(User $user): array
    {
        $this->requireFacilityId($user);

        $reference = RequestFormReferenceData::build();

        return [
            'hospitals' => $this->eligibleHospitals()
                ->map(fn (Facility $hospital): array => [
                    'id' => $hospital->id,
                    'name' => $hospital->name,
                    'address' => $hospital->address,
                    'phone' => $hospital->phone,
                ])
                ->values()
                ->all(),
            'blood_types' => $reference['blood_types'],
            'components' => $reference['components'],
            'priorities' => $reference['priorities'],
            'purpose' => [
                'value' => RequestPurpose::PatientTransfusion->value,
                'label' => RequestPurpose::PatientTransfusion->label(),
            ],
            'id_types' => ValidIdType::options(),
            'duplicate_window_days' => BloodRequestRepository::DUPLICATE_WINDOW_DAYS,
        ];
    }

    /**
     * Look for a requirement the hospital already has open for this patient.
     *
     * Run before the call to the hospital, so staff know what to ask about:
     * whether the watcher's request is one the hospital already sent here,
     * the part of a patient's need nobody is supplying yet, or a second
     * request for the same need.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function findDuplicates(User $user, array $criteria): array
    {
        $centreId = $this->requireFacilityId($user);
        $hospital = $this->requireHospital((int) $criteria['hospital_id']);

        $matches = $this->classify(
            $centreId,
            $this->transfusionRepository->patientMatches($hospital->id, $criteria)
        );

        return [
            'matches' => $matches,
            'requires_acknowledgement' => $matches !== [],
            'window_days' => BloodRequestRepository::DUPLICATE_WINDOW_DAYS,
        ];
    }

    /**
     * Record a walk-in the hospital has confirmed by phone.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function record(User $user, array $payload): array
    {
        $centreId = $this->requireFacilityId($user);
        $hospital = $this->requireHospital((int) $payload['hospital_id']);

        foreach (range(1, self::REFERENCE_ATTEMPTS) as $attempt) {
            try {
                [$requirement, $allocation] = DB::transaction(
                    fn (): array => $this->persist($user, $centreId, $hospital, $payload)
                );

                $allocation->setRelation('transfusionRequest', $requirement);
                $this->notifier->walkInRecorded($allocation);

                return [
                    'message' => "Walk-in request {$requirement->reference_number} recorded for {$hospital->name}.",
                    'request' => $this->projector->project(
                        $this->bloodRequestRepository->loadForProjection($allocation, withUnits: true),
                        withAllocations: true
                    ),
                ];
            } catch (QueryException $exception) {
                if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                    throw $exception;
                }
            }
        }

        throw $this->refuse(409, 'reference_generation_failed', 'Could not allocate a request reference. Please try again.');
    }

    /**
     * Write the requirement (or find it), this centre's allocation and the walk-in row.
     *
     * Under the hospital's facility lock, which its PTR and RQ numbering both
     * queue behind whichever counter the walk-in is keyed at; then the
     * requirement's, when continuing one, so no two writers can hand out the
     * same unallocated unit.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: TransfusionRequest, 1: BloodRequest}
     */
    private function persist(User $user, int $centreId, Facility $hospital, array $payload): array
    {
        $this->bloodRequestRepository->lockFacility($hospital->id)
            ?? throw $this->refuse(404, 'facility_missing', 'That hospital could not be found.');

        $continuing = ! empty($payload['transfusion_request_id']);
        $acknowledgement = trim((string) ($payload['duplicate_acknowledgement'] ?? ''));
        $verification = $payload['verification'];
        $centreName = Facility::query()->whereKey($centreId)->value('name') ?? 'A blood centre';
        $confirmation = "Confirmed by phone with {$verification['verifier_name']} ({$verification['verifier_position']}) of {$hospital->name}.";

        if ($continuing) {
            $requirement = $this->transfusionRepository->lockRaisedBy((int) $payload['transfusion_request_id'], $hospital->id)
                ?? throw ValidationException::withMessages([
                    'transfusion_request_id' => ["That request is not one of {$hospital->name}'s."],
                ]);

            if ($requirement->isClosed()) {
                throw ValidationException::withMessages([
                    'transfusion_request_id' => ["{$requirement->reference_number} is {$requirement->status->label()} and can no longer be added to."],
                ]);
            }
        } else {
            $requirement = null;
        }

        $patient = $requirement ? [
            'patient_surname' => $requirement->patient_surname,
            'patient_first_name' => $requirement->patient_first_name,
            'patient_middle_name' => $requirement->patient_middle_name,
        ] : [
            'patient_surname' => $payload['patient_surname'],
            'patient_first_name' => $payload['patient_first_name'],
            'patient_middle_name' => $payload['patient_middle_name'] ?? null,
        ];

        // Checked inside the lock, so two counters recording the same watcher
        // at the same moment cannot both pass it. The requirement being
        // continued is, of course, not a duplicate of itself.
        $matches = $this->classify($centreId, $this->transfusionRepository->patientMatches(
            $hospital->id,
            [
                ...$patient,
                'blood_type_id' => $requirement?->blood_type_id ?? (int) $payload['blood_type_id'],
                'presented_reference' => $payload['presented_reference'] ?? null,
            ],
            $requirement?->id
        ));

        if ($matches !== [] && $acknowledgement === '') {
            throw new HttpResponseException(response()->json([
                'message' => "{$hospital->name} already has an active request for this patient. "
                    .'Open it, add this to it, or say why a separate request is needed.',
                'code' => 'possible_duplicate',
                'matches' => $matches,
            ], 409));
        }

        if ($requirement === null) {
            $requirement = $this->createRequirement($user, $hospital, $payload, $centreName, $confirmation);
            $lines = array_map(fn (array $item): array => [
                'component_id' => (int) $item['component_id'],
                'quantity' => (int) $item['quantity'],
            ], $payload['items']);
        } else {
            $lines = $this->continuationLines($requirement, $payload['items']);
        }

        $from = $requirement->status;
        $figures = $this->transfusionResolver->freshFigures($requirement);
        $requirement->loadMissing('items.component');

        [$share] = $this->writer->resolveShares(
            $requirement,
            [['facility_id' => $centreId, 'lines' => $lines]],
            $figures,
            'items'
        );

        $allocation = $this->writer->create(
            $requirement,
            $share['facility'],
            $share['lines'],
            $user,
            RequestSource::BloodCenterWalkIn,
            RequestEventType::WalkInRecorded,
            $confirmation.($continuing ? " Added to {$requirement->reference_number}." : ''),
        );

        $representative = $payload['representative'];

        BloodRequestWalkIn::query()->create([
            'request_id' => $allocation->id,
            'representative_name' => $representative['name'],
            'representative_relationship' => $representative['relationship'],
            'representative_contact' => $representative['contact'],
            'representative_id_type' => $representative['id_type'] ?? null,
            'representative_id_number' => $representative['id_number'] ?? null,
            'presented_reference' => $payload['presented_reference'] ?? null,
            'attending_physician' => $payload['attending_physician'] ?? null,
            'patient_ward' => $payload['patient_ward'] ?? null,
            'patient_record_number' => $payload['patient_record_number'] ?? null,
            'verifier_name' => $verification['verifier_name'],
            'verifier_position' => $verification['verifier_position'],
            'verifier_contact' => $verification['verifier_contact'],
            'verified_at' => $verification['verified_at'],
            'verification_recorded_by' => $user->id,
            'verification_notes' => $verification['notes'] ?? null,
            'duplicate_acknowledgement' => $acknowledgement === '' ? null : $acknowledgement,
        ]);

        $this->transfusionResolver->settle($requirement);

        if ($continuing) {
            $this->history->recordForRequirement(
                $requirement,
                RequestEventType::AllocationsAdded,
                $user,
                $from,
                "Walk-in at {$centreName}: asked for {$allocation->quantity} more unit(s). {$confirmation}",
                ['allocations' => [$allocation->reference_number]],
            );
        }

        // Identifiers only. Neither the watcher's nor the verifier's details
        // belong in the audit trail.
        $this->auditLogger->record($user, 'request.walk_in_recorded', $allocation, array_filter([
            'facility_id' => $centreId,
            'hospital_facility_id' => $hospital->id,
            'reference_number' => $allocation->reference_number,
            'transfusion_reference_number' => $requirement->reference_number,
            'quantity' => $allocation->quantity,
            'components' => $allocation->items->pluck('component.name')->all(),
            'urgency_level' => $allocation->urgency_level->value,
            'continued' => $continuing ? true : null,
            'duplicate_acknowledged' => $acknowledgement !== '' ? true : null,
        ], fn ($value): bool => $value !== null));

        return [$requirement, $allocation];
    }

    /**
     * Record the patient's requirement a walk-in brought to the counter.
     *
     * The hospital confirmed the need on the phone. Nobody at the hospital
     * keyed it in, so no requester is named; the centre staff member who did
     * is the recorder.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createRequirement(User $user, Facility $hospital, array $payload, string $centreName, string $confirmation): TransfusionRequest
    {
        $requirement = TransfusionRequest::query()->create([
            'reference_number' => $this->transfusionRepository->nextReference($hospital->id),
            'facility_id' => $hospital->id,
            'requested_by' => null,
            'recorded_by' => $user->id,
            'request_source' => RequestSource::BloodCenterWalkIn,
            'patient_surname' => $payload['patient_surname'],
            'patient_first_name' => $payload['patient_first_name'],
            'patient_middle_name' => $payload['patient_middle_name'] ?? null,
            'patient_age' => $payload['patient_age'],
            'patient_sex' => $payload['patient_sex'],
            'blood_type_id' => (int) $payload['blood_type_id'],
            'urgency_level' => UrgencyLevel::from($payload['urgency_level']),
            'request_date' => now(),
        ]);

        $requirement->items()->createMany(array_map(fn (array $item): array => [
            'component_id' => $item['component_id'],
            'quantity' => $item['quantity'],
            'indication_code' => $item['indication_code'] ?? null,
            'indication_other' => $item['indication_other'] ?? null,
        ], $payload['items']));

        $this->history->recordForRequirement(
            $requirement,
            RequestEventType::TransfusionCreated,
            $user,
            null,
            "Walk-in at {$centreName}. {$confirmation}",
        );

        return $requirement;
    }

    /**
     * Resolve a continuation's lines to the requirement lines they are a share of.
     *
     * Component and indication come from the requirement — they were certified
     * once, when it was recorded — so a line names only which requirement line
     * it answers and how many units.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{component_id: int, quantity: int}>
     */
    private function continuationLines(TransfusionRequest $requirement, array $items): array
    {
        $requirementItems = $requirement->items()->get()->keyBy('id');
        $lines = [];

        foreach (array_values($items) as $index => $item) {
            $line = $requirementItems->get((int) ($item['transfusion_request_item_id'] ?? 0))
                ?? throw ValidationException::withMessages([
                    "items.{$index}.transfusion_request_item_id" => ["That component is not on {$requirement->reference_number}."],
                ]);

            $lines[] = ['component_id' => (int) $line->component_id, 'quantity' => (int) $item['quantity']];
        }

        return $lines;
    }

    /**
     * Describe each matching requirement by what the centre should do about it.
     *
     *  - here: this centre already has an open allocation of it — open that
     *    rather than record it again;
     *  - continue: part of the patient's need is asked of nobody yet — add
     *    this centre's share to the same requirement;
     *  - duplicate: anything else — a second request for the same patient,
     *    allowed only with a reason.
     *
     * Deliberately a stub. The centre is shown as much as it needs to act: the
     * reference, how far the requirement has got, and per component what the
     * patient needs and how much of it is still unallocated.
     *
     * @param  Collection<int, TransfusionRequest>  $requirements
     * @return array<int, array<string, mixed>>
     */
    private function classify(int $centreId, Collection $requirements): array
    {
        return $requirements->map(function (TransfusionRequest $requirement) use ($centreId): array {
            $figures = $this->transfusionResolver->figures($requirement);
            $unallocated = (int) $figures->sum('unallocated');

            $here = $requirement->facilityAllocations->first(
                fn (BloodRequest $allocation): bool => (int) $allocation->target_facility_id === $centreId && ! $allocation->isClosed()
            );

            $relation = match (true) {
                $here !== null => 'here',
                ! $requirement->isClosed() && $unallocated > 0 => 'continue',
                default => 'duplicate',
            };

            return [
                'id' => $requirement->id,
                'reference_number' => $requirement->reference_number,
                'relation' => $relation,
                'allocation' => $here ? [
                    'id' => $here->id,
                    'reference_number' => $here->reference_number,
                ] : null,
                'facilities' => $requirement->facilityAllocations
                    ->reject(fn (BloodRequest $allocation): bool => in_array($allocation->status, [BloodRequestStatus::Rejected, BloodRequestStatus::Cancelled], true))
                    ->map(fn (BloodRequest $allocation): ?string => $allocation->targetFacility?->name)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
                'request_source' => $requirement->request_source->value,
                'source_label' => $requirement->request_source->label(),
                'status' => $requirement->status->value,
                'status_label' => $requirement->status->label(),
                'is_open' => ! $requirement->isClosed(),
                'request_date' => $requirement->request_date?->toIso8601String(),
                'blood_type' => [
                    'id' => $requirement->blood_type_id,
                    'code' => $requirement->bloodType?->code,
                ],
                'urgency_level' => $requirement->urgency_level->value,
                'patient' => $this->projector->patientBlock($requirement),
                'lines' => $requirement->items->sortBy('id')->values()->map(fn ($item): array => [
                    'transfusion_request_item_id' => $item->id,
                    'component' => [
                        'id' => $item->component_id,
                        'name' => $item->component?->name,
                    ],
                    'required' => (int) $item->quantity,
                    'approved' => (int) ($figures->get($item->id)['approved'] ?? 0),
                    'fulfilled' => (int) ($figures->get($item->id)['fulfilled'] ?? 0),
                    'unallocated' => (int) ($figures->get($item->id)['unallocated'] ?? 0),
                ])->all(),
                'unallocated_quantity' => $unallocated,
            ];
        })->values()->all();
    }

    /**
     * Every hospital blood bank a walk-in may be recorded for.
     *
     * @return Collection<int, Facility>
     */
    private function eligibleHospitals(): Collection
    {
        return Facility::query()
            ->approved()
            ->whereHas('facilityType', fn ($query) => $query->where('name', FacilityTypeName::BloodBank->value))
            ->orderBy('name')
            ->get();
    }

    /**
     * Resolve the patient's hospital, refusing anything but a registered blood bank.
     *
     * The hospital is the institutional party the request belongs to, and the
     * one that confirmed it by phone. A facility that is not an approved
     * hospital blood bank cannot be either.
     */
    private function requireHospital(int $hospitalId): Facility
    {
        $hospital = Facility::query()->with('facilityType')->find($hospitalId);

        if (
            ! $hospital
            || $hospital->facilityType?->name !== FacilityTypeName::BloodBank->value
            || $hospital->status !== FacilityStatus::Approved
        ) {
            throw ValidationException::withMessages([
                'hospital_id' => ['Choose a registered hospital blood bank.'],
            ]);
        }

        return $hospital;
    }

    /**
     * Resolve the caller's facility, refusing a staff account without one.
     */
    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw $this->refuse(
            404,
            'facility_missing',
            'This account is not linked to a facility.'
        );
    }

    /**
     * Build the project's standard refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
