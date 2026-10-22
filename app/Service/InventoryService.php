<?php

namespace App\Service;

use App\Enums\BloodUnitStatus;
use App\Enums\DonationStatus;
use App\Models\AuditLog;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use App\Repository\BloodComponentRepository;
use App\Repository\ClearanceRepository;
use App\Repository\InventoryRepository;
use App\Support\BagExpiry;
use App\Support\BagNumbers;
use App\Support\DonorBlinding;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection as SupportCollection;
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
     * The donation status whose bags may be booked in.
     *
     * Kept as the named gate even though DonationStatus::acceptsIntake() is
     * what the check calls, so that grepping for the intake rule still lands
     * here. `completed` alone is not enough: recordUnits() also wants both
     * clearances and Processing's final labels, and books the bags straight
     * into stock. There is no quarantine step any more (project owner,
     * 2026-10-22). See "Release step removed" in
     * docs/IMPLEMENTATION_DECISIONS.md.
     */
    private const INTAKE_DONATION_STATUS = DonationStatus::Completed;

    /**
     * How many past days the summary's trend covers, before today.
     */
    private const TREND_DAYS = 7;

    /**
     * The audit actions that make up a centre's inventory activity, and what
     * each one is called on the page.
     *
     * Only actions recorded against a unit, and only ones this centre takes: a
     * hospital receiving a bag is audited on the same unit but is not stock
     * moving here.
     */
    private const ACTIVITY_KINDS = [
        'inventory.recorded' => 'booked_in',
        'inventory.released_from_quarantine' => 'released',
        'allocation.reserved' => 'reserved',
        'allocation.issued' => 'issued',
        'allocation.expired_hold_released' => 'hold_returned',
        'inventory.updated' => 'updated',
        'inventory.reinstated' => 'reinstated',
        'inventory.discarded' => 'discarded',
        'inventory.expired' => 'expired',
    ];

    public function __construct(
        private readonly InventoryRepository $inventoryRepository,
        private readonly BloodComponentRepository $bloodComponentRepository,
        private readonly AuditLogger $auditLogger,
        private readonly ClearanceRepository $clearanceRepository
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
    /**
     * Donations cleared for issue that still have units to book in.
     *
     * The laboratory hands a donation over by setting it `completed`; this is
     * the other side of that handover. It exists as its own endpoint because
     * Issuance holds `donations.view` but not `lab.view`, so the laboratory
     * queue is closed to them, and because neither that queue nor
     * GET /blood-center/donations carries the declaration ledger this needs.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function intakeQueue(User $user, int $perPage, ?string $barcode = null): LengthAwarePaginator
    {
        $facilityId = $this->requireFacilityId($user);

        // Resolved once for the page rather than per donation: shelf life is a
        // property of this facility's configuration, not of a donation.
        $settings = $this->bloodComponentRepository->settingsFor($facilityId);

        // A scanned sticker, normalised as the counter stored it.
        $barcode = $barcode === null ? null : strtoupper((string) preg_replace('/[\s\p{Cc}]+/u', '', $barcode));

        return $this->inventoryRepository
            ->paginateIntakeQueue($facilityId, $perPage, $barcode === '' ? null : $barcode)
            ->through(fn (Donation $donation): array => $this->formatIntake($donation, $settings, $user));
    }

    /**
     * One donation as the intake screen needs it.
     *
     * @param  SupportCollection<int, FacilityBloodComponent>  $settings
     * @return array<string, mixed>
     */
    private function formatIntake(Donation $donation, SupportCollection $settings, User $viewer): array
    {
        $ledger = $this->declarationLedger($donation);
        $components = $donation->components->keyBy('component_id');
        $slots = BagNumbers::slots($donation);

        $rows = [];

        foreach ($ledger as $componentId => $row) {
            $component = $components->get($componentId)?->component;
            $setting = $settings->get($componentId);
            $bags = $slots[$componentId] ?? [];
            $outstanding = array_slice($bags, $row['recorded']);

            $rows[] = [
                ...$row,
                'component' => $component?->name,
                // Every declared bag's volume, and those still to be shelved.
                // Null for a bag declared before volumes were kept.
                'volumes' => array_column($bags, 'volume_ml'),
                'outstanding_volumes' => array_column($outstanding, 'volume_ml'),
                // The bags still to be shelved, by the number on their Phase 1
                // label. Booking one in gives the unit that number. Null for a
                // donation with no barcode, which keeps the generated RA… ids.
                'outstanding_bags' => array_map(fn (array $slot): array => [
                    'bag_number' => $slot['bag_number'],
                    'volume_ml' => $slot['volume_ml'],
                ], $outstanding),
                // The expiry Processing printed on the final label (BagExpiry),
                // offered so the stock record agrees with the bag.
                'expiry_date' => BagExpiry::date($donation, $setting?->shelf_life_days),
                // The intake screen refuses a component with no shelf life
                // rather than letting someone type an expiry out of the air,
                // so it has to know before offering the row. Read from this
                // facility's settings, never the shared catalogue row.
                'shelf_life_days' => $setting?->shelf_life_days,
                'shelf_life_configured' => $setting?->hasShelfLife() ?? false,
            ];
        }

        return [
            'donation_id' => $donation->id,
            'donation_date' => $donation->donation_date?->toISOString(),
            'volume_ml' => $donation->volume_ml,
            // The type on the profile, which is what intake stamps onto every
            // unit, shown so staff can see it matches the bag. The donor's
            // identity only to a role that meets donors: Issuance books in by
            // barcode. See DonorBlinding.
            'donor' => DonorBlinding::block(
                $donation->donorProfile?->donor,
                $donation->donorProfile?->bloodType?->code,
                $viewer
            ),

            // What is printed on the bag, so it can be matched without a name.
            'donation_barcode' => $donation->collection?->donation_barcode,
            'components' => $rows,
            // Whether the bags can be booked in: cleared and labelled. Only a
            // donation handed over before labelling moved to Processing
            // (2026-10-22) can be neither, and recordUnits() refuses it.
            'into_stock' => $donation->labelled_at !== null && $this->clearanceRepository->hasBoth($donation->id),
            'declared_units' => array_sum(array_column($ledger, 'declared')),
            'recorded_units' => array_sum(array_column($ledger, 'recorded')),
            'outstanding_units' => array_sum(array_column($ledger, 'outstanding')),
        ];
    }

    public function summary(User $user): array
    {
        $facilityId = $this->requireFacilityId($user);
        $today = OperationalDay::todayAsDate();
        [$dayStart, $dayEnd] = OperationalDay::boundsFor();
        $totals = $this->inventoryRepository->summaryCounts($facilityId);
        $nearExpiry = $this->inventoryRepository->nearExpiryCounts($facilityId, $today);

        return [
            'totals' => $totals,
            'by_blood_type' => $this->inventoryRepository->countsByBloodType($facilityId),
            // Each type's shelf split into fresh, expiring, held and not yet
            // cleared, for the Issuance dashboard's type cards.
            'stock_by_blood_type' => $this->inventoryRepository->stockByBloodType($facilityId, $today),
            'by_component' => $this->inventoryRepository->countsByComponent($facilityId),
            'near_expiry' => $nearExpiry,
            'trend' => $this->trend($facilityId, $totals, $nearExpiry),
            // Units that left this centre for a request today.
            'issued_today' => $this->inventoryRepository->issuedCountBetween($facilityId, $dayStart, $dayEnd),
            // Units that joined issuable stock this week, out of quarantine.
            // now() rather than the operational clock: released_at is stored
            // in the application's timezone, and so is this bound.
            'released_last_7_days' => $this->inventoryRepository->releasedToStockSince($facilityId, CarbonImmutable::now()->subDays(7)),
            'storage_locations' => $this->storageLocations($facilityId),
            'as_of' => OperationalDay::today()->toIso8601String(),
        ];
    }

    /**
     * The four headline levels at the close of each of the last seven
     * operational days, then today, oldest first.
     *
     * The past days are rebuilt from timestamps (see levelsAt()). Today is the
     * live count the dashboard's cards already show, so a sparkline always
     * ends on the number beside it.
     *
     * @param  array<string, int>  $totals
     * @param  array<string, int>  $nearExpiry
     * @return array<int, array{date: string, available: int, expiring_soon: int, reserved: int, quarantined: int}>
     */
    private function trend(int $facilityId, array $totals, array $nearExpiry): array
    {
        $today = OperationalDay::today()->startOfDay();
        $points = [];

        foreach (range(self::TREND_DAYS, 1) as $daysAgo) {
            $date = $today->subDays($daysAgo)->toDateString();

            $points[] = [
                'date' => $date,
                ...$this->inventoryRepository->levelsAt($facilityId, OperationalDay::boundsFor($date)[1], $date),
            ];
        }

        $points[] = [
            'date' => $today->toDateString(),
            'available' => $totals[BloodUnitStatus::Available->value] ?? 0,
            'expiring_soon' => $nearExpiry['within_7_days'] ?? 0,
            'reserved' => $totals[BloodUnitStatus::Reserved->value] ?? 0,
            'quarantined' => $totals[BloodUnitStatus::Quarantined->value] ?? 0,
        ];

        return $points;
    }

    /**
     * What has happened to the caller's facility stock, newest first.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function activity(User $user, ?string $unitId, int $perPage): LengthAwarePaginator
    {
        $facilityId = $this->requireFacilityId($user);

        return $this->inventoryRepository
            ->paginateActivity($facilityId, array_keys(self::ACTIVITY_KINDS), $unitId, $perPage)
            ->through(fn (AuditLog $entry): array => [
                'id' => $entry->id,
                'kind' => self::ACTIVITY_KINDS[$entry->action],
                'unit_id' => $entry->auditable_id,
                'blood_type' => $entry->getAttribute('blood_type_code'),
                'component' => $entry->getAttribute('component_name'),
                // Null for the expiry sweep, which has no one behind it.
                'actor' => $entry->actor === null
                    ? null
                    : $entry->actor->signatureName(),
                'at' => $entry->created_at?->toIso8601String(),
            ]);
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
                        ? 'Blood unit booked into stock.'
                        : count($units).' blood units booked into stock.',
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

            // A quarantined unit may be discarded: for a donation that came
            // back reactive it is the only way off the shelf.
            if (! in_array($unit->status, [BloodUnitStatus::Available, BloodUnitStatus::Expired, BloodUnitStatus::Quarantined], true)) {
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

        if (! $donation->status->acceptsIntake()) {
            throw $this->refuse(
                409,
                'donation_not_completed',
                'Processing has not completed this donation, so its bags cannot be booked in yet.'
            );
        }

        // Issuance books in only bags Testing has cleared and Processing has
        // labelled (project owner, 2026-10-22). The hand-over already demands
        // both, so this catches a donation handed over before that. It is
        // refused rather than held: there is no quarantine to release it from.
        if (! $this->clearanceRepository->hasBoth($donation->id)) {
            throw $this->refuse(
                409,
                'donation_not_cleared',
                'Testing has not cleared this donation, so its bags cannot be booked in yet.'
            );
        }

        if ($donation->labelled_at === null) {
            throw $this->refuse(
                409,
                'labels_not_printed',
                'Processing has not printed this donation\'s final labels yet. They print them from the Processed list.'
            );
        }

        $bloodTypeId = $this->requireDonorBloodType($donation);

        $this->guardAgainstLaboratoryDeclaration($donation, $payload['units']);

        $prefix = $this->generatedIdPrefix($facilityId, $donation->id);
        $sequence = $this->nextSequence($donation->id, $prefix);

        // Each unit is the next un-booked bag of its component: it takes that
        // bag's volume and, when the donation has a barcode, that bag's
        // number (BagNumbers). The donation lock held above is what makes
        // "next" safe.
        $slots = BagNumbers::slots($donation);
        $booked = array_map(
            fn (array $row): int => $row['recorded'],
            $this->declarationLedger($donation)
        );

        $plan = [];

        foreach ($payload['units'] as $index => $entry) {
            $componentId = (int) $entry['component_id'];
            $slot = $slots[$componentId][$booked[$componentId] ?? 0] ?? null;
            $booked[$componentId] = ($booked[$componentId] ?? 0) + 1;

            $bagNumber = $slot['bag_number'] ?? null;
            $supplied = $entry['unit_id'] ?? null;

            // A barcoded bag is numbered from its sticker, which is already on
            // the Phase 1 label. A typed number could only disagree with it.
            if ($bagNumber !== null && $supplied !== null && $supplied !== $bagNumber) {
                throw ValidationException::withMessages([
                    "units.{$index}.unit_id" => ['Bags from a barcoded donation are numbered from their sticker.'],
                ]);
            }

            $plan[] = [$entry, $slot['volume_ml'] ?? null, $bagNumber ?? $supplied];

            if ($bagNumber !== null) {
                $bagNumbers[] = $bagNumber;
            }
        }

        // Unit ids are global and a sticker series only unique per centre,
        // so this is where two centres printing the same numbers would meet.
        // A typed number (legacy donations) is the validator's to refuse.
        $bagNumbers ??= [];

        foreach ($this->inventoryRepository->existingIdsAmong($bagNumbers) as $taken) {
            throw $this->refuse(409, 'bag_number_taken', "Bag {$taken} already exists. Check the sticker on the bag.");
        }

        // Cleared and labelled (checked above), so straight into stock.
        $now = now();

        $units = [];

        foreach ($plan as [$entry, $volume, $unitId]) {
            if ($unitId === null) {
                $unitId = $prefix.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
                $sequence++;
            }

            $units[] = $this->inventoryRepository->createUnit([
                'id' => $unitId,
                'facility_id' => $facilityId,
                'component_id' => $entry['component_id'],
                // From the bag Processing declared, never from this request.
                'volume_ml' => $volume,
                // Derived server-side and never accepted from the client. It is
                // the one field on a unit that can kill someone if it is wrong,
                // and the donation already knows it.
                'blood_type_id' => $bloodTypeId,
                'donation_id' => $donation->id,
                'storage_location' => $entry['storage_location'] ?? null,
                'expiry_date' => $entry['expiry_date'],
                'status' => BloodUnitStatus::Available,
                // The booking is when it joined issuable stock, and the officer
                // who booked it is who released it.
                'released_at' => $now,
                'released_by' => $user->id,
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
     * Public so a unit-details correction is judged by the very rule a direct
     * edit is, both when it is filed and when it is approved.
     *
     * @param  array<string, mixed>  $payload
     */
    public function guardEditable(BloodUnit $unit, array $payload): void
    {
        // A quarantined unit can have its shelf and date corrected like any
        // other. Its status is untouched here: update() only ever changes the
        // status of an expired unit, so no edit can move one out of quarantine.
        if (in_array($unit->status, [BloodUnitStatus::Available, BloodUnitStatus::Quarantined], true)) {
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
            'volume_ml' => $unit->volume_ml,
            'status' => $unit->status->value,
            'expiry_date' => $unit->expiry_date?->toDateString(),
            'days_remaining' => $unit->expiry_date
                ? OperationalDay::daysUntil(CarbonImmutable::parse($unit->expiry_date))
                : null,
            'storage_location' => $unit->storage_location,
            'donation_id' => $unit->donation_id,
            // When the bag was collected: its donation's date. Read only when
            // the listing loaded it, so a single-unit response costs no query.
            'collected_at' => $unit->relationLoaded('donation')
                ? $unit->donation?->donation_date?->toIso8601String()
                : null,
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
        $ledger = $this->declarationLedger($donation);

        if ($ledger === []) {
            throw $this->refuse(
                409,
                'components_not_declared',
                'The laboratory has not recorded what this donation was separated into, so its units cannot be booked in.'
            );
        }

        $requested = [];

        foreach ($entries as $index => $entry) {
            $componentId = (int) $entry['component_id'];

            if (! isset($ledger[$componentId])) {
                throw ValidationException::withMessages([
                    "units.{$index}.component_id" => ['The laboratory did not record this component for this donation.'],
                ]);
            }

            $requested[$componentId] = ($requested[$componentId] ?? 0) + 1;
        }

        foreach ($requested as $componentId => $count) {
            $row = $ledger[$componentId];

            if ($count > $row['outstanding']) {
                throw $this->refuse(
                    409,
                    'exceeds_declared_quantity',
                    "The laboratory declared {$row['declared']} unit(s) of this component for this donation; {$row['outstanding']} may still be recorded."
                );
            }
        }
    }

    /**
     * What the laboratory declared for a donation, against what is already in.
     *
     * The single source for both the intake queue and the guard above. They
     * must not each count this themselves: a screen that offered a unit the
     * guard then refused would send staff back and forth with a 409 and no way
     * to tell which of the two was wrong.
     *
     * @return array<int, array{component_id: int, declared: int, recorded: int, outstanding: int}>
     */
    private function declarationLedger(Donation $donation): array
    {
        // Summed, not plucked: one row per bag means a component can appear
        // on several rows, and a plucked map would keep only the last.
        $declared = $donation->components()
            ->selectRaw('component_id, SUM(quantity) as declared')
            ->groupBy('component_id')
            ->pluck('declared', 'component_id');

        $recorded = $donation->bloodUnits()
            ->selectRaw('component_id, count(*) as total')
            ->groupBy('component_id')
            ->pluck('total', 'component_id');

        $ledger = [];

        foreach ($declared as $componentId => $quantity) {
            $used = (int) ($recorded->get($componentId) ?? 0);

            $ledger[(int) $componentId] = [
                'component_id' => (int) $componentId,
                'declared' => (int) $quantity,
                'recorded' => $used,
                'outstanding' => max(0, (int) $quantity - $used),
            ];
        }

        return $ledger;
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
