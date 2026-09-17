<?php

namespace App\Service;

use App\Models\BloodComponent;
use App\Models\User;
use App\Repository\BloodComponentRepository;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The platform's blood component catalogue, and the shelf life that drives
 * every unit's expiry date.
 *
 * `BloodComponentSeeder` ships component names only and leaves `shelf_life_days`
 * NULL deliberately, because shelf life varies by preparation, anticoagulant and
 * storage protocol and therefore has to come from a named clinical owner rather
 * than a table baked into source. This service is that owner's way in. Nothing
 * here invents a default: a component with no shelf life stays unconfigured, and
 * inventory intake refuses it rather than guessing an expiry date.
 */
class BloodComponentService
{
    public function __construct(
        private readonly BloodComponentRepository $bloodComponentRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * List every component with its configuration state.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $components = $this->bloodComponentRepository->all()
            ->map(fn (BloodComponent $component): array => $this->format($component))
            ->all();

        return [
            'data' => $components,
            // Surfaced so the admin screen can say how much is still outstanding
            // without the client re-deriving the same rule.
            'meta' => [
                'unconfigured' => count(array_filter(
                    $components,
                    static fn (array $row): bool => $row['shelf_life_days'] === null
                )),
            ],
        ];
    }

    /**
     * Set a component's shelf life and storage temperature.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(User $admin, int $componentId, array $payload): array
    {
        $component = $this->bloodComponentRepository->find($componentId)
            ?? throw $this->refuse(404, 'component_not_found', 'That blood component was not found.');

        $before = $component->shelf_life_days;

        $component = $this->bloodComponentRepository->update($component, [
            'shelf_life_days' => $payload['shelf_life_days'] ?? null,
            'storage_temperature' => isset($payload['storage_temperature'])
                ? trim((string) $payload['storage_temperature']) ?: null
                : null,
        ]);

        // Both values are recorded because this changes the expiry date of every
        // unit booked in from now on, and "who set 35 days, and when" is the
        // question an audit of a mis-dated unit begins with.
        $this->auditLogger->record($admin, 'admin.component_updated', $component, [
            'shelf_life_days_before' => $before,
            'shelf_life_days_after' => $component->shelf_life_days,
        ]);

        return [
            'message' => 'Component updated.',
            'data' => $this->format($component),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function format(BloodComponent $component): array
    {
        return [
            'id' => $component->id,
            'name' => $component->name,
            'shelf_life_days' => $component->shelf_life_days,
            'storage_temperature' => $component->storage_temperature,
            // The same flag BloodCenterService::referenceData exposes, so the
            // admin screen and the intake screen agree on what "configured"
            // means without either of them defining it.
            'shelf_life_configured' => $component->hasShelfLife(),
        ];
    }

    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'code' => $code,
            'message' => $message,
        ], $status));
    }
}
