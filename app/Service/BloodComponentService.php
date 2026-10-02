<?php

namespace App\Service;

use App\Models\BloodComponent;
use App\Models\Facility;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use App\Repository\BloodComponentRepository;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A blood centre's own settings for each blood component.
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
     * This facility's component settings, and how much is still outstanding.
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
     * Set this facility's shelf life and price for one component.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(User $staff, int $componentId, array $payload): array
    {
        $facility = $this->requireFacility($staff);

        $component = $this->bloodComponentRepository->find($componentId)
            ?? throw $this->refuse(404, 'component_not_found', 'That blood component was not found.');

        $before = $this->bloodComponentRepository->setting($facility->id, $component->id);

        $setting = $this->bloodComponentRepository->upsertSetting($facility->id, $component->id, [
            'shelf_life_days' => $payload['shelf_life_days'] ?? null,
            'price' => $payload['price'] ?? null,
            // From the authenticated user, never the request body.
            'updated_by' => $staff->id,
        ]);

        // Both values are recorded because between them they decide when a unit
        // expires and whether it can be released without payment. "Who set 35
        // days, and when" is the question an audit of a mis-dated unit starts
        // with.
        $this->auditLogger->record($staff, 'center.component_configured', $setting, [
            'facility_id' => $facility->id,
            'component_id' => $component->id,
            'shelf_life_days_before' => $before?->shelf_life_days,
            'shelf_life_days_after' => $setting->shelf_life_days,
            'price_before' => $before?->price,
            'price_after' => $setting->price,
        ]);

        return [
            'message' => "{$component->name} updated for {$facility->name}.",
            'data' => $this->format($component, $setting),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function format(BloodComponent $component, ?FacilityBloodComponent $setting): array
    {
        return [
            'id' => $component->id,
            'name' => $component->name,
            'shelf_life_days' => $setting?->shelf_life_days,
            'price' => $setting?->price === null ? null : (float) $setting->price,
            'storage_temperature' => $component->storage_temperature,
            // The same flag the reference endpoint exposes, so this screen and
            // stock intake agree on what "configured" means rather than each
            // defining it.
            'shelf_life_configured' => $setting?->hasShelfLife() ?? false,
        ];
    }

    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'code' => $code,
            'message' => $message,
        ], $status));
    }
}
