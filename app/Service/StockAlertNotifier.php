<?php

namespace App\Service;

use App\Enums\FacilityTypeName;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\LowStockAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears that a facility's stock fell below its minimum.
 *
 * Unlike BloodRequestNotifier, `send()` is called inside the sweep's
 * transaction and lets any exception propagate: the notification rows and the
 * cells' `alerted_at` must commit together or not at all, so a failed send has
 * to roll the mark back rather than be logged and swallowed.
 */
class StockAlertNotifier
{
    /**
     * Notify a facility's recipients that the given cells are below their minimum.
     *
     * @param  array<int, array{blood_type_id: int, blood_type_code: string, component_id: int, component_name: string, available: int, minimum_units: int, status: string}>  $items
     * @return int How many accounts were notified.
     */
    public function send(Facility $facility, array $items): int
    {
        $recipients = $this->recipients($facility);

        if ($recipients->isEmpty() || $items === []) {
            return 0;
        }

        Notification::send($recipients, new LowStockAlert($this->typeOf($facility), $facility->id, $items));

        return $recipients->count();
    }

    /**
     * The accounts at a facility that should hear about its stock.
     *
     * A blood centre tells whoever can see its inventory: the ability, not a
     * department, so a custom role capped below `inventory.view` is correctly
     * left out and a supervisor is correctly in. A hospital blood bank has no
     * departments or abilities, so every account there does the same job and
     * every account hears.
     *
     * @return Collection<int, User>
     */
    public function recipients(Facility $facility): Collection
    {
        $accounts = User::query()->where('facility_id', $facility->id)->get();

        if ($this->typeOf($facility) === FacilityTypeName::BloodBank) {
            return $accounts;
        }

        return $accounts
            ->filter(fn (User $user): bool => in_array('inventory.view', $user->abilities(), true))
            ->values();
    }

    private function typeOf(Facility $facility): FacilityTypeName
    {
        $facility->loadMissing('facilityType');

        return FacilityTypeName::tryFrom((string) $facility->facilityType?->name) ?? FacilityTypeName::BloodCenter;
    }
}
