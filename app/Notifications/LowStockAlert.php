<?php

namespace App\Notifications;

use App\Enums\FacilityTypeName;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells a facility's staff that one or more blood stocks fell below their minimum.
 *
 * One notification covers every cell that started a low episode in the same
 * sweep, so a shelf that empties all at once is one message and not a dozen.
 *
 * Stored in-app only, and deliberately neither queued nor mailed. The sweep
 * writes these rows in the same transaction as the cells' `alerted_at`, which
 * is what makes "marked alerted" and "actually notified" one fact: a queued or
 * mailed channel would commit the mark before the send had happened, and a
 * failed send would then suppress the alert for the rest of the episode.
 * StockThresholdTest pins both properties.
 */
class LowStockAlert extends Notification
{
    use Queueable;

    /**
     * @param  array<int, array{blood_type_id: int, blood_type_code: string, component_id: int, component_name: string, available: int, minimum_units: int, status: string}>  $items
     */
    public function __construct(
        private readonly FacilityTypeName $facilityType,
        private readonly int $facilityId,
        private readonly array $items
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Build the in-app copy both portals' notification lists show.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $count = count($this->items);
        $first = $this->items[0] ?? null;

        $title = $count === 1 && $first !== null
            ? "Low stock: {$first['blood_type_code']} {$first['component_name']}"
            : "Low stock: {$count} blood stocks below minimum";

        $desc = collect($this->items)
            ->map(fn (array $item): string => "{$item['blood_type_code']} {$item['component_name']} {$item['available']} of {$item['minimum_units']}")
            ->implode(' · ');

        $critical = collect($this->items)->contains(fn (array $item): bool => $item['status'] === 'critical');

        return [
            'category' => 'inventory',
            'title' => $title,
            'desc' => $desc,
            'tone' => $critical ? 'danger' : 'warning',
            'icon' => 'triangle-alert',
            'action_label' => 'View thresholds',
            'action_route' => $this->facilityType === FacilityTypeName::BloodBank
                ? '/hospital/stock-thresholds'
                : '/blood-center/stock-thresholds',
            'facility_id' => $this->facilityId,
            'items' => $this->items,
        ];
    }
}
