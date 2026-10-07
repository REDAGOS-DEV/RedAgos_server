<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Models\BloodUnit;
use App\Models\DirectDistribution;
use App\Models\ExternalBloodSource;
use App\Models\TransfusionRequest;
use App\Models\User;
use App\Repository\BloodRequestRepository;
use App\Repository\DirectDistributionRepository;
use App\Repository\HospitalInventoryRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Blood received from outside RedAgos, booked into the hospital's own stock.
 *
 * Two ways in. For a Patient Transfusion Request, one bag at a time, scanned
 * or typed, and linked to the requirement. Without one, typed by hand: one
 * identifier for as many bags as arrived, and who asked for them.
 *
 * The identifier is kept as its sender gave it — "PRC-920923323" from the
 * Philippine Red Cross — and RedAgos issues no barcode of its own. It is
 * identified by its source plus that identifier. Each bag is a blood_units row
 * keyed internally (DD-{id}, DD-{id}-2, …), booked under the receiving hospital
 * and already `issued`, so no centre query ever counts it, and it enters the
 * hospital's custody as available stock. A bag received for a requirement is
 * linked to it but does not change the requirement's figures, which count what
 * centres supply.
 */
class DirectDistributionService
{
    public function __construct(
        private readonly DirectDistributionRepository $repository,
        private readonly HospitalInventoryRepository $inventoryRepository,
        private readonly BloodRequestRepository $bloodRequestRepository,
        private readonly HospitalInventoryService $hospitalInventoryService,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Receive external blood: one bag for a Patient Transfusion Request, or a typed batch without one.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function receive(User $user, array $payload): array
    {
        $facilityId = $this->requireFacilityId($user);

        try {
            $delivery = DB::transaction(fn (): DirectDistribution => $this->persist($user, $facilityId, $payload));
        } catch (QueryException $exception) {
            // A concurrent receipt of the same identifier lost the race to the unique index.
            if (in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                throw $this->alreadyReceived();
            }

            throw $exception;
        }

        $count = $delivery->quantity;

        return [
            'message' => $count === 1
                ? "Bag {$delivery->external_unit_number} received into your blood bank."
                : "{$count} bags under {$delivery->external_unit_number} received into your blood bank.",
            'direct_distribution' => $this->format($this->repository->findFor($delivery->id, $facilityId)),
        ];
    }

    /**
     * List the caller hospital's receipts, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->repository
            ->paginateFor($this->requireFacilityId($user), $filters, $perPage)
            ->through(fn (DirectDistribution $delivery): array => $this->format($delivery));
    }

    /**
     * The blood services blood can be received from.
     *
     * @return array{sources: array<int, array<string, mixed>>}
     */
    public function sources(User $user): array
    {
        $this->requireFacilityId($user);

        return ['sources' => $this->repository->sources()
            ->map(fn (ExternalBloodSource $source): array => $this->formatSource($source))
            ->values()
            ->all()];
    }

    /**
     * Add a blood service to the list.
     *
     * Names are unique ignoring case, so "philippine red cross" cannot become a
     * second source beside the seeded one.
     *
     * @return array<string, mixed>
     */
    public function addSource(User $user, string $name, ?string $code): array
    {
        $this->requireFacilityId($user);
        $name = trim($name);

        if ($this->repository->findSourceByName($name) !== null) {
            throw ValidationException::withMessages(['name' => ['That blood service is already on the list.']]);
        }

        $source = $this->repository->createSource([
            'name' => $name,
            'code' => $code !== null && trim($code) !== '' ? strtoupper(trim($code)) : null,
            'created_by' => $user->id,
        ]);

        $this->auditLogger->record($user, 'external_blood_source.created', $source, ['name' => $source->name]);

        return ['message' => "{$source->name} added.", 'source' => $this->formatSource($source)];
    }

    /**
     * Write the receipt, its bags and their custody under the hospital's lock.
     *
     * @param  array<string, mixed>  $payload
     */
    private function persist(User $user, int $facilityId, array $payload): DirectDistribution
    {
        $this->bloodRequestRepository->lockFacility($facilityId)
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');

        $requirement = isset($payload['transfusion_request_id'])
            ? $this->requireOpenRequirement((int) $payload['transfusion_request_id'], $facilityId)
            : null;

        $number = (string) $payload['external_unit_number'];
        // A bag scanned for a patient is one bag; a typed batch says how many.
        $quantity = $requirement !== null ? 1 : (int) ($payload['quantity'] ?? 1);

        // A RedAgos bag is received from its own request, with its own barcode;
        // booking it again as external would put one bag in stock twice.
        if ($this->repository->redagosUnitExists($number)) {
            throw $this->refuse(409, 'redagos_unit', 'This is a RedAgos bag. Receive it from the request it was dispatched for.');
        }

        if ($this->repository->receiptExists((int) $payload['external_blood_source_id'], $number)) {
            throw $this->alreadyReceived();
        }

        $delivery = DirectDistribution::query()->create([
            'facility_id' => $facilityId,
            'transfusion_request_id' => $requirement?->id,
            'requested_for' => $requirement === null ? trim((string) $payload['requested_for']) : null,
            'external_blood_source_id' => (int) $payload['external_blood_source_id'],
            'external_unit_number' => $number,
            'quantity' => $quantity,
            'collection_date' => $payload['collection_date'] ?? null,
            // A time typed without an offset was read off a Manila clock, and
            // the column holds the application's own wall clock.
            'received_at' => isset($payload['received_at'])
                ? CarbonImmutable::parse($payload['received_at'], (string) config('blood_center.timezone', 'Asia/Manila'))
                    ->setTimezone((string) config('app.timezone', 'UTC'))
                : now(),
            'received_by' => $user->id,
        ]);

        $keys = array_map(fn (int $sequence): string => DirectDistribution::unitKey($delivery->id, $sequence), range(1, $quantity));

        if ($this->repository->takenUnitIds($keys) !== []) {
            throw $this->refuse(409, 'unit_key_taken', 'A bag number clashes with one already recorded. Please try again.');
        }

        /** @var Collection<int, BloodUnit> $bags */
        $bags = collect($keys)->map(fn (string $key): BloodUnit => $this->repository->createUnit([
            'id' => $key,
            'facility_id' => $facilityId,
            'component_id' => (int) $payload['component_id'],
            'blood_type_id' => (int) $payload['blood_type_id'],
            'donation_id' => null,
            'direct_distribution_id' => $delivery->id,
            'volume_ml' => isset($payload['volume_ml']) ? (int) $payload['volume_ml'] : null,
            'expiry_date' => $payload['expiry_date'],
            // Issued, as a bag a centre dispatched is: in a hospital's custody
            // and out of every centre's stock.
            'status' => BloodUnitStatus::Issued,
        ]));

        $this->hospitalInventoryService->stockDelivered($user, $facilityId, $delivery, $bags);

        // Who it was requested for can be a patient's name, so it stays out of
        // the audit log, as patient names do elsewhere.
        $this->auditLogger->record($user, 'direct_distribution.received', $delivery, array_filter([
            'facility_id' => $facilityId,
            'transfusion_request_id' => $requirement?->id,
            'external_blood_source_id' => $delivery->external_blood_source_id,
            'external_unit_number' => $number,
            'quantity' => $quantity,
        ], fn ($value): bool => $value !== null));

        return $delivery;
    }

    /**
     * Resolve one of this hospital's Patient Transfusion Requests that is not cancelled.
     */
    private function requireOpenRequirement(int $transfusionRequestId, int $facilityId): TransfusionRequest
    {
        $requirement = $this->inventoryRepository->findTransfusionRequestFor($transfusionRequestId, $facilityId)
            ?? throw $this->refuse(404, 'transfusion_request_not_found', 'That Patient Transfusion Request was not found.');

        if ($requirement->status === BloodRequestStatus::Cancelled) {
            throw $this->refuse(409, 'transfusion_request_closed', 'That Patient Transfusion Request was cancelled.');
        }

        return $requirement;
    }

    /**
     * Project a receipt for the API.
     *
     * The bags share their type, component, volume and expiry — they were
     * entered once for the receipt — so those are read off the first.
     *
     * @return array<string, mixed>
     */
    private function format(DirectDistribution $delivery): array
    {
        $bags = $delivery->bloodUnits
            ->sortBy(fn (BloodUnit $bag): int => DirectDistribution::sequenceOf($bag->id))
            ->values();
        $first = $bags->first();
        $expiry = $first?->expiry_date;
        $receiver = $delivery->receiver;

        return [
            'id' => $delivery->id,
            'external_unit_number' => $delivery->external_unit_number,
            'quantity' => $delivery->quantity,
            'source' => $delivery->source === null ? null : $this->formatSource($delivery->source),
            'transfusion_request' => $delivery->transfusionRequest === null ? null : [
                'id' => $delivery->transfusionRequest->id,
                'reference_number' => $delivery->transfusionRequest->reference_number,
            ],
            'requested_for' => $delivery->requested_for,
            'blood_type' => ['id' => $first?->blood_type_id, 'code' => $first?->bloodType?->code],
            'component' => ['id' => $first?->component_id, 'name' => $first?->component?->name],
            'volume_ml' => $first?->volume_ml,
            'collection_date' => $delivery->collection_date?->toDateString(),
            'expiry_date' => $expiry?->toDateString(),
            'days_remaining' => $expiry ? OperationalDay::daysUntil($expiry) : null,
            'units' => $bags->map(fn (BloodUnit $bag): array => [
                'unit_id' => $bag->id,
                'bag_number' => $delivery->bagNumberFor($bag->id),
                'status' => $bag->hospitalUnit?->status->value,
                'status_label' => $bag->hospitalUnit?->status->label(),
            ])->all(),
            'received_at' => $delivery->received_at?->toIso8601String(),
            'received_by' => $receiver === null ? null : trim($receiver->first_name.' '.$receiver->last_name),
        ];
    }

    /**
     * Project a source for the API.
     *
     * @return array{id: int, name: string, code: string|null}
     */
    private function formatSource(ExternalBloodSource $source): array
    {
        return ['id' => $source->id, 'name' => $source->name, 'code' => $source->code];
    }

    /**
     * Refuse an identifier the source has already delivered.
     */
    private function alreadyReceived(): HttpResponseException
    {
        return $this->refuse(409, 'external_unit_already_received', 'Blood under this identifier has already been received from that blood service.');
    }

    /**
     * Resolve the caller's facility, refusing a staff account without one.
     */
    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    /**
     * Build the project's standard refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
