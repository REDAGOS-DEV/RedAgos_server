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
use App\Models\User;
use App\Repository\BloodRequestRepository;
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
 * The result is an ordinary request in the hospital's own RQ sequence. It sits
 * in the hospital's list, goes through the same allocation, release and receipt
 * as any other, and differs only in its source and in the walk-in row recording
 * who presented it and who at the hospital confirmed it.
 */
class WalkInRequestService
{
    private const REFERENCE_ATTEMPTS = 3;

    public function __construct(
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly BloodRequestProjector $projector,
        private readonly RequestStatusResolver $resolver,
        private readonly BloodRequestHistory $history,
        private readonly BloodRequestNotifier $notifier,
        private readonly FollowUpRequestService $followUps,
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
     * Look for a request the hospital already has open for this patient.
     *
     * Run before the call to the hospital, so staff know what to ask about:
     * whether the watcher's request is one the hospital already sent here,
     * the remainder of one another centre could only part-fill, or a second
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
            $this->bloodRequestRepository->patientMatches($hospital->id, $criteria)
        );

        return [
            'matches' => $matches,
            'requires_acknowledgement' => $matches !== [],
            'window_days' => BloodRequestRepository::DUPLICATE_WINDOW_DAYS,
        ];
    }

    /**
     * Record a walk-in request the hospital has confirmed by phone.
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
                $request = DB::transaction(
                    fn (): BloodRequest => $this->persist($user, $centreId, $hospital, $payload)
                );

                $this->notifier->walkInRecorded($request);

                return [
                    'message' => "Walk-in request {$request->reference_number} recorded for {$hospital->name}.",
                    'request' => $this->projector->project(
                        $this->bloodRequestRepository->loadForProjection($request, withUnits: true),
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
     * Write the request, its lines and its walk-in row under the hospital's lock.
     *
     * The hospital's facility row is what its reference numbering queues
     * behind, whichever counter the request is keyed in at.
     *
     * @param  array<string, mixed>  $payload
     */
    private function persist(User $user, int $centreId, Facility $hospital, array $payload): BloodRequest
    {
        $this->bloodRequestRepository->lockFacility($hospital->id)
            ?? throw $this->refuse(404, 'facility_missing', 'That hospital could not be found.');

        $parent = null;
        $followUpLines = null;

        if (! empty($payload['parent_request_id'])) {
            $parent = $this->bloodRequestRepository->lockRaisedBy((int) $payload['parent_request_id'], $hospital->id)
                ?? throw ValidationException::withMessages([
                    'parent_request_id' => ["That request is not one of {$hospital->name}'s."],
                ]);

            $followUpLines = $this->followUps->assertForwardable($parent, $centreId, $payload['items']);
        }

        $patient = $parent ? [
            'patient_surname' => $parent->patient_surname,
            'patient_first_name' => $parent->patient_first_name,
            'patient_middle_name' => $parent->patient_middle_name,
            'patient_age' => $parent->patient_age,
            'patient_sex' => $parent->patient_sex,
        ] : [
            'patient_surname' => $payload['patient_surname'],
            'patient_first_name' => $payload['patient_first_name'],
            'patient_middle_name' => $payload['patient_middle_name'] ?? null,
            'patient_age' => $payload['patient_age'],
            'patient_sex' => $payload['patient_sex'],
        ];

        $bloodTypeId = $parent ? $parent->blood_type_id : (int) $payload['blood_type_id'];
        $acknowledgement = trim((string) ($payload['duplicate_acknowledgement'] ?? ''));

        // Checked inside the lock, so two counters recording the same watcher
        // at the same moment cannot both pass it.
        $matches = $this->classify($centreId, $this->bloodRequestRepository->patientMatches(
            $hospital->id,
            [
                ...$patient,
                'blood_type_id' => $bloodTypeId,
                'presented_reference' => $payload['presented_reference'] ?? null,
            ],
            $parent?->id
        ));

        if ($matches !== [] && $acknowledgement === '') {
            throw new HttpResponseException(response()->json([
                'message' => "{$hospital->name} already has an active request for this patient. "
                    .'Open it, record this as a follow-up, or say why a separate request is needed.',
                'code' => 'possible_duplicate',
                'matches' => $matches,
            ], 409));
        }

        $request = BloodRequest::query()->create([
            'reference_number' => $this->bloodRequestRepository->nextReference($hospital->id),
            'parent_request_id' => $parent?->id,
            'facility_id' => $hospital->id,
            'target_facility_id' => $centreId,
            'requested_by' => null,
            'recorded_by' => $user->id,
            'request_purpose' => RequestPurpose::PatientTransfusion,
            'request_source' => RequestSource::BloodCenterWalkIn,
            ...$patient,
            'blood_type_id' => $bloodTypeId,
            'urgency_level' => UrgencyLevel::from($payload['urgency_level']),
            'status' => BloodRequestStatus::Pending,
            'request_date' => now(),
        ]);

        if ($followUpLines !== null) {
            $this->followUps->createLines($request, $followUpLines);
        } else {
            $request->items()->createMany(array_map(fn (array $item): array => [
                'component_id' => $item['component_id'],
                'quantity' => $item['quantity'],
                'indication_code' => $item['indication_code'] ?? null,
                'indication_other' => $item['indication_other'] ?? null,
            ], $payload['items']));
        }

        $representative = $payload['representative'];
        $verification = $payload['verification'];

        BloodRequestWalkIn::query()->create([
            'request_id' => $request->id,
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

        $request->load(['items.component', 'targetFacility', 'requestingFacility']);

        $this->history->record(
            $request,
            RequestEventType::WalkInRecorded,
            $user,
            null,
            "Confirmed by phone with {$verification['verifier_name']} ({$verification['verifier_position']}) of {$hospital->name}."
                .($parent ? " Follow-up of {$parent->reference_number}." : ''),
            related: $parent,
            meta: array_filter([
                'duplicate_acknowledged' => $acknowledgement !== '' ? true : null,
            ]),
        );

        if ($parent !== null) {
            $this->followUps->linkToParent($parent, $request, $user);
        }

        // Identifiers only. Neither the watcher's nor the verifier's details
        // belong in the audit trail.
        $this->auditLogger->record($user, 'request.walk_in_recorded', $request, array_filter([
            'facility_id' => $centreId,
            'hospital_facility_id' => $hospital->id,
            'reference_number' => $request->reference_number,
            'quantity' => $request->quantity,
            'components' => $request->items->pluck('component.name')->all(),
            'urgency_level' => $request->urgency_level->value,
            'parent_reference_number' => $parent?->reference_number,
            'duplicate_acknowledged' => $acknowledgement !== '' ? true : null,
        ], fn ($value): bool => $value !== null));

        return $request;
    }

    /**
     * Describe each matching request by what the centre should do about it.
     *
     *  - here: already addressed to this centre and still open — open it
     *    rather than record it again;
     *  - follow_up: at another centre, which supplied or reserved part of it
     *    and has a remainder left — record this as a follow-up of it;
     *  - duplicate: anything else — a second request for the same patient,
     *    allowed only with a reason.
     *
     * Deliberately a stub. A request addressed to another centre is shown only
     * as far as this centre needs to act: its reference, where it is, how far
     * it has got, and what is left to source.
     *
     * @param  Collection<int, BloodRequest>  $requests
     * @return array<int, array<string, mixed>>
     */
    private function classify(int $centreId, Collection $requests): array
    {
        return $requests->map(function (BloodRequest $request) use ($centreId): array {
            $figures = $this->resolver->figures($request);
            $here = (int) $request->target_facility_id === $centreId;
            $progress = (int) ($figures->sum('fulfilled') + $figures->sum('reserved'));
            $forwardable = (int) $figures->sum('forwardable');

            $relation = match (true) {
                $here && ! $request->isClosed() => 'here',
                ! $here && $forwardable > 0 && $progress > 0 => 'follow_up',
                default => 'duplicate',
            };

            return [
                'id' => $request->id,
                'reference_number' => $request->reference_number,
                'relation' => $relation,
                'facility' => $request->targetFacility ? [
                    'id' => $request->targetFacility->id,
                    'name' => $request->targetFacility->name,
                ] : null,
                'request_source' => $request->request_source->value,
                'source_label' => $request->request_source->label(),
                'status' => $request->status->value,
                'status_label' => $request->status->label(),
                'is_open' => ! $request->isClosed(),
                'request_date' => $request->request_date?->toIso8601String(),
                'blood_type' => [
                    'id' => $request->blood_type_id,
                    'code' => $request->bloodType?->code,
                ],
                'urgency_level' => $request->urgency_level->value,
                'patient' => [
                    'surname' => $request->patient_surname,
                    'first_name' => $request->patient_first_name,
                    'middle_name' => $request->patient_middle_name,
                    'full_name' => $request->patientFullName(),
                    'age' => $request->patient_age,
                    'sex' => $request->patient_sex,
                ],
                'lines' => $request->items->sortBy('id')->values()->map(fn ($item): array => [
                    'request_item_id' => $item->id,
                    'component' => [
                        'id' => $item->component_id,
                        'name' => $item->component?->name,
                    ],
                    'requested' => $item->quantity,
                    'reserved' => (int) ($figures->get($item->id)['reserved'] ?? 0),
                    'fulfilled' => (int) ($figures->get($item->id)['fulfilled'] ?? 0),
                    'forwardable' => (int) ($figures->get($item->id)['forwardable'] ?? 0),
                ])->all(),
                'forwardable_quantity' => $forwardable,
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
