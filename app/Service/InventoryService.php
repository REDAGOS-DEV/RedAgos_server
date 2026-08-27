<?php

namespace App\Service;

use App\Enums\BloodUnitStatus;
use App\Enums\DonationStatus;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\User;
use App\Repository\InventoryRepository;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules for a blood centre's own stock.
 *
 * Reads are facility-scoped in the repository. Writes go through a transaction
 * and a locked re-read, and each records an audit entry. The expiry sweep's own
 * entries belong to the command rather than here: it is the one mutation in
 * this module with no user behind it.
 */
class InventoryService
{
    /**
     * How many times intake will retry a generated-id collision before giving up.
     */
    private const ID_ATTEMPTS = 3;

    /**
     * The donation status that may become issuable stock.
     *
     * Kept as the named gate even though DonationStatus::isIssuable() is what
     * the check calls, so that grepping for the intake rule still lands here.
     * Confirmed as "tested and cleared for issue" — see the donation-status
     * entry in docs/IMPLEMENTATION_DECISIONS.md. If that confirmation is ever
     * overturned, isIssuable() is the single place to change, but the module
     * would then also need a quarantine state before units could be created
     * available.
     */
    private const ISSUABLE_DONATION_STATUS = DonationStatus::Completed;

    public function __construct(
        private readonly InventoryRepository $inventoryRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * List the caller's facility stock.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $facilityId = $this->requireFacilityId($user);
        $filters['operational_date'] = OperationalDay::todayAsDate();

        return $this->inventoryRepository
            ->paginateUnits($facilityId, $filters, $perPage)
            ->through(fn (BloodUnit $unit): array => $this->format($unit));
    }

    /**
     * Summarise the caller's facility stock.
     *
     * Every number is derived from units, never from a stored counter.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $facilityId = $this->requireFacilityId($user);
        $today = OperationalDay::todayAsDate();

        return [
            'totals' => $this->inventoryRepository->summaryCounts($facilityId),
            'by_blood_type' => $this->inventoryRepository->countsByBloodType($facilityId),
            'by_component' => $this->inventoryRepository->countsByComponent($facilityId),
            'near_expiry' => $this->inventoryRepository->nearExpiryCounts($facilityId, $today),
            'storage_locations' => $this->storageLocations($facilityId),
            'as_of' => OperationalDay::today()->toIso8601String(),
        ];
    }

    /**
     * Record collected units against a completed donation.
     *
     * Wrapped in a bounded retry because the donation lock cannot cover
     * everything: a staff-supplied unit_id is checked by the validator and
     * inserted later, potentially against concurrent intake for a DIFFERENT
     * donation, which no donation lock serialises.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function record(User $user, array $payload): array
    {
        $facilityId = $this->requireFacilityId($user);

        foreach (range(1, self::ID_ATTEMPTS) as $attempt) {
            try {
                $units = DB::transaction(
                    fn (): array => $this->recordUnits($user, $facilityId, $payload)
                );

                return [
                    'message' => count($units) === 1
                        ? 'Blood unit recorded.'
                        : count($units).' blood units recorded.',
                    'units' => array_map(fn (BloodUnit $unit): array => $this->format($unit), $units),
                ];
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                // A staff-supplied id that is now taken is deterministic —
                // retrying would fail identically three times, so it returns the
                // same 422 the validator would have given a moment earlier.
                if ($collided = $this->suppliedIdsNowTaken($payload)) {
                    throw ValidationException::withMessages($collided);
                }
            }
        }

        throw $this->refuse(409, 'unit_id_generation_failed', 'Could not allocate a unit number. Please try again.');
    }

    /**
     * Correct a unit's storage location or expiry date.
     *
     * An expired unit may have its date corrected and nothing else, which is
     * what rescues a mistyped year the sweep has already acted on.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(User $user, string $unitId, array $payload): array
    {
        $facilityId = $this->requireFacilityId($user);

        [$unit, $reinstated, $previousExpiry] = DB::transaction(function () use ($unitId, $facilityId, $payload): array {
            $unit = $this->inventoryRepository->lockUnit($unitId, $facilityId)
                ?? throw $this->refuse(404, 'unit_not_found', 'This blood unit was not found.');

            $this->guardEditable($unit, $payload);

            $previousExpiry = $unit->expiry_date?->toDateString();
            $wasExpired = $unit->status === BloodUnitStatus::Expired;

            if (array_key_exists('storage_location', $payload)) {
                $unit->storage_location = $payload['storage_location'];
            }

            if (array_key_exists('expiry_date', $payload)) {
                $unit->expiry_date = $payload['expiry_date'];
            }

            // An expired unit given a date that is no longer past returns to the
            // shelf. The request rule already forbids a past date, so reaching
            // here with one is not possible.
            $reinstated = $wasExpired && array_key_exists('expiry_date', $payload);

            if ($reinstated) {
                $unit->status = BloodUnitStatus::Available;
                $unit->expired_at = null;
            }

            $unit->save();

            return [$unit, $reinstated, $previousExpiry];
        });

        // Deliberately its own action rather than inventory.updated: an
        // un-expiry is the one edit here worth being able to find later.
        $this->auditLogger->record($user, $reinstated ? 'inventory.reinstated' : 'inventory.updated', $unit, array_filter([
            'facility_id' => $unit->facility_id,
            'previous_expiry_date' => $reinstated ? $previousExpiry : null,
            'expiry_date' => $unit->expiry_date?->toDateString(),
            'storage_location' => $unit->storage_location,
        ], fn ($value): bool => $value !== null));

        return [
            'message' => $reinstated ? 'Blood unit returned to available stock.' : 'Blood unit updated.',
            'unit' => $this->format($unit),
        ];
    }

    /**
     * Record that a unit has physically left the building.
     *
     * An expired unit may be discarded, and keeps its expired_at: the two facts
     * are separate events and both belong on the row.
     *
     * @return array<string, mixed>
     */
    public function discard(User $user, string $unitId, string $reason): array
    {
        $facilityId = $this->requireFacilityId($user);

        $unit = DB::transaction(function () use ($unitId, $facilityId, $reason): BloodUnit {
            $unit = $this->inventoryRepository->lockUnit($unitId, $facilityId)
                ?? throw $this->refuse(404, 'unit_not_found', 'This blood unit was not found.');

            if (! in_array($unit->status, [BloodUnitStatus::Available, BloodUnitStatus::Expired], true)) {
                throw $this->refuse(
                    409,
                    'unit_not_discardable',
                    $unit->status === BloodUnitStatus::Discarded
                        ? 'This blood unit has already been discarded.'
                        : 'A unit held for a blood request cannot be discarded here.'
                );
            }

            $unit->status = BloodUnitStatus::Discarded;
            $unit->discard_reason = $reason;
            $unit->discarded_at = OperationalDay::today();
            $unit->save();

            return $unit;
        });

        $this->auditLogger->record($user, 'inventory.discarded', $unit, [
            'facility_id' => $unit->facility_id,
            'discard_reason' => $reason,
            // Kept in the trail so a discarded-after-expiry unit still shows
            // both events without a second lookup.
            'expired_at' => $unit->expired_at?->toIso8601String(),
        ]);

        return [
            'message' => 'Blood unit discarded.',
            'unit' => $this->format($unit),
        ];
    }

    /**
     * Insert the units, under the donation lock.
     *
     * Everything happens after the lock — status check, blood-type read,
     * sequence derivation, inserts — because the lock is on the row the
     * sequence is namespaced by.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, BloodUnit>
     */
    private function recordUnits(User $user, int $facilityId, array $payload): array
    {
        $donation = $this->inventoryRepository->lockDonation((int) $payload['donation_id'], $facilityId)
            ?? throw $this->refuse(404, 'donation_not_found', 'This donation was not found at your facility.');

        if (! $donation->status->isIssuable()) {
            throw $this->refuse(
                409,
                'donation_not_completed',
                'This donation has not been completed, so its blood cannot enter inventory yet.'
            );
        }

        $bloodTypeId = $this->requireDonorBloodType($donation);

        $this->guardAgainstLaboratoryDeclaration($donation, $payload['units']);

        $prefix = $this->generatedIdPrefix($facilityId, $donation->id);
        $sequence = $this->nextSequence($donation->id, $prefix);

        $units = [];

        foreach ($payload['units'] as $entry) {
            $unitId = $entry['unit_id'] ?? null;

            if ($unitId === null) {
                $unitId = $prefix.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
                $sequence++;
            }

            $units[] = $this->inventoryRepository->createUnit([
                'id' => $unitId,
                'facility_id' => $facilityId,
                'component_id' => $entry['component_id'],
                // Derived server-side and never accepted from the client. It is
                // the one field on a unit that can kill someone if it is wrong,
                // and the donation already knows it.
                'blood_type_id' => $bloodTypeId,
                'donation_id' => $donation->id,
                'storage_location' => $entry['storage_location'] ?? null,
                'expiry_date' => $entry['expiry_date'],
                'status' => BloodUnitStatus::Available,
            ]);
        }

        foreach ($units as $unit) {
            $this->auditLogger->record($user, 'inventory.recorded', $unit, [
                'facility_id' => $facilityId,
                'donation_id' => $donation->id,
                'expiry_date' => $unit->expiry_date?->toDateString(),
            ]);
        }

        return $units;
    }

    /**
     * The prefix generated ids for this donation share.
     */
    private function generatedIdPrefix(int $facilityId, int $donationId): string
    {
        return "RA{$facilityId}-{$donationId}-";
    }

    /**
     * The next sequence number for a donation's generated ids.
     *
     * Parsed in PHP rather than taken from a lexicographic MAX(id), which is
     * wrong the moment the sequence passes 99 ('RA4-118-100' < 'RA4-118-99'),
     * and rather than counting the donation's units, which is wrong the moment
     * one was recorded with a staff-supplied id. The set is one donation's
     * units — a handful of rows, not a table scan.
     */
    private function nextSequence(int $donationId, string $prefix): int
    {
        $highest = 0;

        foreach ($this->inventoryRepository->existingUnitIds($donationId, $prefix) as $existingId) {
            $suffix = substr($existingId, strlen($prefix));

            if (ctype_digit($suffix)) {
                $highest = max($highest, (int) $suffix);
            }
        }

        return $highest + 1;
    }

    /**
     * The donor's blood type, or a refusal.
     */
    private function requireDonorBloodType(Donation $donation): int
    {
        $donation->loadMissing('donorProfile');

        return $donation->donorProfile?->blood_type_id
            ?? throw $this->refuse(
                422,
                'donor_blood_type_missing',
                'This donor has no blood type on file, so their donation cannot be recorded as stock.'
            );
    }

    /**
     * Refuse an edit the unit's state does not allow.
     *
     * @param  array<string, mixed>  $payload
     */
    private function guardEditable(BloodUnit $unit, array $payload): void
    {
        if ($unit->status === BloodUnitStatus::Available) {
            return;
        }

        // An expired unit is editable in exactly one way: its date. Allowing the
        // storage location too would make "correct the typo" and "quietly move
        // expired stock" the same request.
        if ($unit->status === BloodUnitStatus::Expired && ! array_key_exists('storage_location', $payload)) {
            return;
        }

        throw $this->refuse(
            409,
            'unit_not_editable',
            $unit->status === BloodUnitStatus::Expired
                ? 'An expired unit can only have its expiry date corrected.'
                : 'This blood unit can no longer be edited.'
        );
    }

    /**
     * Union the configured storage locations with the ones actually recorded.
     *
     * @return array<int, string>
     */
    private function storageLocations(int $facilityId): array
    {
        $configured = (array) config('blood_center.storage_locations', []);
        $recorded = $this->inventoryRepository->distinctStorageLocations($facilityId);

        $all = array_values(array_unique([...$configured, ...$recorded]));

        sort($all);

        return $all;
    }

    /**
     * Project a unit for the API.
     *
     * @return array<string, mixed>
     */
    private function format(BloodUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'blood_type' => [
                'id' => $unit->blood_type_id,
                'code' => $unit->bloodType?->code,
            ],
            'component' => [
                'id' => $unit->component_id,
                'name' => $unit->component?->name,
            ],
            'status' => $unit->status->value,
            'expiry_date' => $unit->expiry_date?->toDateString(),
            'days_remaining' => $unit->expiry_date
                ? OperationalDay::daysUntil(CarbonImmutable::parse($unit->expiry_date))
                : null,
            'storage_location' => $unit->storage_location,
            'donation_id' => $unit->donation_id,
            'recorded_at' => $unit->created_at?->toIso8601String(),
            'expired_at' => $unit->expired_at?->toIso8601String(),
            'discarded_at' => $unit->discarded_at?->toIso8601String(),
            'discard_reason' => $unit->discard_reason,
        ];
    }

    /**
     * Which supplied unit ids are now taken, mapped back to their input field.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, array<int, string>>
     */
    private function suppliedIdsNowTaken(array $payload): array
    {
        $supplied = [];

        foreach ($payload['units'] as $index => $entry) {
            if (isset($entry['unit_id'])) {
                $supplied[$entry['unit_id']] = "units.{$index}.unit_id";
            }
        }

        $errors = [];

        foreach ($this->inventoryRepository->existingIdsAmong(array_keys($supplied)) as $takenId) {
            $errors[$supplied[$takenId]] = ['This unit number has already been recorded.'];
        }

        return $errors;
    }

    /**
     * Whether a query failure is a unique-index collision.
     *
     * Matched on SQLSTATE rather than a driver-specific message string:
     * PostgreSQL reports 23505, MySQL and sqlite report 23000.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true);
    }

    /**
     * Refuse units the laboratory never said this donation yielded.
     *
     * Laboratory declares which components a donation was separated into and
     * how many bags of each; inventory records the units from that declaration.
     * Without this check `component_id` is free text validated only against the
     * component table, so a bag could be booked in as a component that was
     * never produced — see "Who records blood component information" in
     * docs/IMPLEMENTATION_DECISIONS.md.
     *
     * Counts existing units too, so the limit holds across several intakes
     * rather than only within one request.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function guardAgainstLaboratoryDeclaration(Donation $donation, array $entries): void
    {
        $declared = $donation->components()->pluck('quantity', 'component_id');

        if ($declared->isEmpty()) {
            throw $this->refuse(
                409,
                'components_not_declared',
                'The laboratory has not recorded what this donation was separated into, so its units cannot be booked in.'
            );
        }

        $alreadyRecorded = $donation->bloodUnits()
            ->selectRaw('component_id, count(*) as total')
            ->groupBy('component_id')
            ->pluck('total', 'component_id');

        $requested = [];

        foreach ($entries as $index => $entry) {
            $componentId = (int) $entry['component_id'];

            if (! $declared->has($componentId)) {
                throw ValidationException::withMessages([
                    "units.{$index}.component_id" => ['The laboratory did not record this component for this donation.'],
                ]);
            }

            $requested[$componentId] = ($requested[$componentId] ?? 0) + 1;
        }

        foreach ($requested as $componentId => $count) {
            $limit = (int) $declared->get($componentId);
            $used = (int) ($alreadyRecorded->get($componentId) ?? 0);

            if ($used + $count > $limit) {
                $remaining = max(0, $limit - $used);

                throw $this->refuse(
                    409,
                    'exceeds_declared_quantity',
                    "The laboratory declared {$limit} unit(s) of this component for this donation; {$remaining} may still be recorded."
                );
            }
        }
    }

    /**
     * The facility the caller acts for.
     *
     * Resolved from the authenticated user, never from request input, so there
     * is no IDOR surface.
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
