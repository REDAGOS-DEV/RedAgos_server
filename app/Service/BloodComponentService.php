<?php

namespace App\Service;

use App\Models\BloodComponent;
use App\Models\Facility;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use App\Repository\BloodComponentRepository;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A blood centre's own settings for each blood component, and the catalogue itself.
 *
 * `BloodComponentSeeder` ships component names only. Shelf life and price are
 * left unset deliberately: shelf life varies by preparation, anticoagulant and
 * storage protocol, so it has to come from a named clinical owner rather than a
 * table baked into source, and price is a commercial decision each centre makes
 * for itself.
 *
 * Both are held per facility rather than on the shared `blood_components` row,
 * because that row has no facility_id and four facilities share it. One centre
 * editing it would change another centre's expiry dates, and — since a non-zero
 * price switches on the payment-before-release gate in BillingService — could
 * block releases at a facility that never set a price at all.
 *
 * Each is set by its own department, and each write touches only its own
 * column (project owner, 2026-10-15): Processing adds components and sets
 * their shelf life (components.configure), Billing prices them
 * (billing.prices).
 *
 * Nothing here invents a default. A component this facility has not configured
 * stays unconfigured, and stock intake refuses it rather than guessing.
 */
class BloodComponentService
{
    public function __construct(
        private readonly BloodComponentRepository $bloodComponentRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * This facility's shelf life for each component, and how many still have none.
     *
     * @return array<string, mixed>
     */
    public function index(User $staff): array
    {
        $facility = $this->requireFacility($staff);
        $settings = $this->bloodComponentRepository->settingsFor($facility->id);

        $components = $this->bloodComponentRepository->all()
            ->map(fn (BloodComponent $component): array => $this->format(
                $component,
                $settings->get($component->id)
            ))
            ->all();

        return [
            'data' => $components,
            'meta' => [
                // Surfaced so the screen can say how much is still outstanding
                // without re-deriving the rule the intake screen already uses.
                'unconfigured' => count(array_filter(
                    $components,
                    static fn (array $row): bool => $row['shelf_life_days'] === null
                )),
                'facility' => ['id' => $facility->id, 'name' => $facility->name],
            ],
        ];
    }

    /**
     * Add a component to the shared catalogue, with this facility's shelf life for it if given.
     *
     * The catalogue has no facility_id, so every centre and every hospital's
     * request form sees the new component at once. Other centres see it as
     * not configured, and cannot book it into stock, until they set their own
     * shelf life.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(User $staff, array $payload): array
    {
        $facility = $this->requireFacility($staff);
        $shelfLife = $payload['shelf_life_days'] ?? null;

        try {
            [$component, $setting] = DB::transaction(function () use ($staff, $facility, $payload, $shelfLife): array {
                $component = $this->bloodComponentRepository->create([
                    'name' => $payload['name'],
                    'code' => $payload['code'],
                    'storage_temperature' => $payload['storage_temperature'] ?? null,
                ]);

                $setting = $shelfLife === null ? null : $this->bloodComponentRepository->upsertSetting(
                    $facility->id,
                    $component->id,
                    ['shelf_life_days' => $shelfLife, 'updated_by' => $staff->id]
                );

                return [$component, $setting];
            });
        } catch (QueryException $exception) {
            $this->rethrowUniqueViolation($exception);

            throw $exception;
        }

        $this->auditLogger->record($staff, 'center.component_added', $component, [
            'facility_id' => $facility->id,
            'component_id' => $component->id,
            'name' => $component->name,
            'code' => $component->code,
        ]);

        if ($setting !== null) {
            $this->recordShelfLife($staff, $facility, $component, null, $setting);
        }

        return [
            'message' => $setting === null
                ? "{$component->name} added. Set its shelf life before booking it into stock."
                : "{$component->name} added with a shelf life of {$setting->shelf_life_days} days.",
            'data' => $this->format($component, $setting),
        ];
    }

    /**
     * Set this facility's shelf life for one component.
     *
     * Writes the shelf life only: the price on the same row is Billing's.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateShelfLife(User $staff, int $componentId, array $payload): array
    {
        $facility = $this->requireFacility($staff);
        $component = $this->findComponent($componentId);

        $before = $this->bloodComponentRepository->setting($facility->id, $component->id);

        $setting = $this->bloodComponentRepository->upsertSetting($facility->id, $component->id, [
            'shelf_life_days' => $payload['shelf_life_days'],
            // From the authenticated user, never the request body.
            'updated_by' => $staff->id,
        ]);

        $this->recordShelfLife($staff, $facility, $component, $before, $setting);

        return [
            'message' => "{$component->name} updated for {$facility->name}.",
            'data' => $this->format($component, $setting),
        ];
    }

    /**
     * This facility's price for each component, and how many have none.
     *
     * @return array<string, mixed>
     */
    public function prices(User $staff): array
    {
        $facility = $this->requireFacility($staff);
        $settings = $this->bloodComponentRepository->settingsFor($facility->id);

        $components = $this->bloodComponentRepository->all()
            ->map(fn (BloodComponent $component): array => $this->formatPrice(
                $component,
                $settings->get($component->id)
            ))
            ->all();

        return [
            'data' => $components,
            'meta' => [
                'unpriced' => count(array_filter(
                    $components,
                    static fn (array $row): bool => $row['price'] === null
                )),
                'facility' => ['id' => $facility->id, 'name' => $facility->name],
            ],
        ];
    }

    /**
     * Set this facility's price for every component in one save.
     *
     * All or nothing: the save is refused unless every component in the
     * catalogue ends up with a price or an explicit no charge, so a centre
     * cannot leave one blank by accident (project owner, 2026-10-15). A
     * component the screen did not send counts if it is already configured;
     * one Processing has added since the screen loaded does not, and is named
     * in the refusal.
     *
     * No charge is stored as a price of zero. Billing already reads zero and
     * unset alike, as a ₱0 line settled on creation that never holds a unit
     * back, so what no charge adds is that somebody decided it, and the audit
     * records who.
     *
     * Unchanged lines are skipped, so a save that sends the whole list writes
     * and audits only what changed.
     *
     * @param  array<int, array{component_id: int, no_charge: bool, price?: int|float|string|null}>  $lines
     * @return array<string, mixed>
     */
    public function savePrices(User $staff, array $lines): array
    {
        $facility = $this->requireFacility($staff);

        $changed = DB::transaction(function () use ($staff, $facility, $lines): int {
            $existing = $this->bloodComponentRepository->lockSettingsFor($facility->id);
            $sent = collect($lines)->keyBy(static fn (array $line): int => (int) $line['component_id']);

            $missing = $this->bloodComponentRepository->all()
                ->filter(static fn (BloodComponent $component): bool => ! $sent->has($component->id)
                    && $existing->get($component->id)?->price === null)
                ->values();

            if ($missing->isNotEmpty()) {
                throw $this->refuse(
                    422,
                    'prices_incomplete',
                    'Cannot save prices. Configure a price for: '.$missing->pluck('name')->join(', ').'.',
                    ['components' => $missing->pluck('id')->all()]
                );
            }

            return $sent
                ->filter(fn (array $line, int $componentId): bool => $this->savePrice(
                    $staff,
                    $facility,
                    $componentId,
                    $existing->get($componentId),
                    $line
                ))
                ->count();
        });

        return [
            'message' => match ($changed) {
                0 => 'No prices changed.',
                1 => "1 price saved for {$facility->name}.",
                default => "{$changed} prices saved for {$facility->name}.",
            },
        ] + $this->prices($staff);
    }

    /**
     * Write one line of a price save, unless it is what is already stored.
     *
     * Whether a unit can leave without payment turns on this value, so who set
     * it, and from what, is recorded with it.
     *
     * @param  array{no_charge: bool, price?: int|float|string|null}  $line
     */
    private function savePrice(
        User $staff,
        Facility $facility,
        int $componentId,
        ?FacilityBloodComponent $before,
        array $line
    ): bool {
        $price = $line['no_charge'] ? 0 : $line['price'];

        if ($before?->price !== null && Money::toCentavos($before->price) === Money::toCentavos($price)) {
            return false;
        }

        $setting = $this->bloodComponentRepository->upsertSetting($facility->id, $componentId, [
            'price' => $price,
            'updated_by' => $staff->id,
        ]);

        $this->auditLogger->record($staff, 'billing.component_priced', $setting, [
            'facility_id' => $facility->id,
            'component_id' => $componentId,
            'price_before' => $before?->price,
            'price_after' => $setting->price,
            'no_charge' => (bool) $line['no_charge'],
        ]);

        return true;
    }

    /**
     * "Who set 35 days, and when" is the question an audit of a mis-dated unit
     * starts with, so every shelf life written is recorded with its old value.
     */
    private function recordShelfLife(
        User $staff,
        Facility $facility,
        BloodComponent $component,
        ?FacilityBloodComponent $before,
        FacilityBloodComponent $after
    ): void {
        $this->auditLogger->record($staff, 'center.component_configured', $after, [
            'facility_id' => $facility->id,
            'component_id' => $component->id,
            'shelf_life_days_before' => $before?->shelf_life_days,
            'shelf_life_days_after' => $after->shelf_life_days,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(BloodComponent $component, ?FacilityBloodComponent $setting): array
    {
        return [
            'id' => $component->id,
            'name' => $component->name,
            // What a bag of it is numbered with: 1234567-PRBC.
            'code' => $component->labelCode(),
            'shelf_life_days' => $setting?->shelf_life_days,
            'storage_temperature' => $component->storage_temperature,
            // The same flag the reference endpoint exposes, so this screen and
            // stock intake agree on what "configured" means rather than each
            // defining it.
            'shelf_life_configured' => $setting?->hasShelfLife() ?? false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPrice(BloodComponent $component, ?FacilityBloodComponent $setting): array
    {
        return [
            'id' => $component->id,
            'name' => $component->name,
            'code' => $component->labelCode(),
            'price' => $setting?->price === null ? null : (float) $setting->price,
            // Released without payment by decision, not because nobody set a price.
            'no_charge' => $setting?->price !== null && Money::toCentavos($setting->price) === 0,
        ];
    }

    private function findComponent(int $componentId): BloodComponent
    {
        return $this->bloodComponentRepository->find($componentId)
            ?? throw $this->refuse(404, 'component_not_found', 'That blood component was not found.');
    }

    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    /**
     * Turn a name or code taken by a concurrent add into a field error.
     *
     * StoreBloodComponentRequest checks both first; this is only reached when
     * two adds race past it and collide at the unique index.
     */
    private function rethrowUniqueViolation(QueryException $exception): void
    {
        if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
            return;
        }

        $field = str_contains($exception->getMessage(), 'code') ? 'code' : 'name';

        throw ValidationException::withMessages([
            $field => ['Another component was just added with this '.$field.'.'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function refuse(int $status, string $code, string $message, array $extra = []): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'code' => $code,
            'message' => $message,
            ...$extra,
        ], $status));
    }
}
