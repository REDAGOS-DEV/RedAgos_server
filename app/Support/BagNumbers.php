<?php

namespace App\Support;

use App\Models\Donation;
use App\Models\DonationComponent;
use Illuminate\Support\Collection;

/**
 * Each bag's number: the donation's barcode sticker plus what the bag holds.
 *
 * Every bag from one donation carries the same pre-printed sticker, so a bag
 * is told apart by its component: 1234567-PRBC, 1234567-FFP, and 1234567-PRBC-2
 * for a second bag of the same component. The number is on the Phase 1 label
 * Processing prints, and it becomes the unit's id when Issuance books the bag
 * in — one number from the bench to the patient.
 *
 * Computed on read and never stored. The barcode can still be corrected until
 * the first bag is booked in, and a stored copy would go stale; this cannot.
 *
 * No number is given when there is no barcode (a donation from before
 * stickers), or for a legacy breakdown row that stands for several bags of
 * unknown volume — those units keep the generated RA… id.
 */
final class BagNumbers
{
    /**
     * Each declared bag, in declaration order, grouped by component.
     *
     * A legacy row with `quantity` N expands to N slots, so the n-th unit
     * booked for a component always lines up with the n-th slot here.
     *
     * @return array<int, array<int, array{row_id: int, bag_number: string|null, volume_ml: int|null}>>
     */
    public static function slots(Donation $donation): array
    {
        $barcode = self::barcode($donation);
        $seen = [];
        $slots = [];

        foreach (self::rows($donation) as $row) {
            $componentId = (int) $row->component_id;
            $count = max(1, (int) $row->quantity);
            $numbered = $barcode !== null && $count === 1;

            for ($i = 0; $i < $count; $i++) {
                $number = null;

                if ($numbered) {
                    $seen[$componentId] = ($seen[$componentId] ?? 0) + 1;
                    $code = $row->component?->labelCode() ?? 'X';
                    $number = $barcode.'-'.$code.($seen[$componentId] > 1 ? '-'.$seen[$componentId] : '');
                }

                $slots[$componentId][] = [
                    'row_id' => (int) $row->id,
                    'bag_number' => $number,
                    'volume_ml' => $row->volume_ml === null ? null : (int) $row->volume_ml,
                ];
            }
        }

        return $slots;
    }

    /**
     * The bag number for each declared row, keyed by the row's id.
     *
     * @return array<int, string|null>
     */
    public static function forRows(Donation $donation): array
    {
        $numbers = [];

        foreach (self::slots($donation) as $componentSlots) {
            foreach ($componentSlots as $slot) {
                $numbers[$slot['row_id']] ??= $slot['bag_number'];
            }
        }

        // Declaration order, not grouped by component.
        ksort($numbers);

        return $numbers;
    }

    /**
     * The donation's barcode, or null when it has none.
     */
    public static function barcode(Donation $donation): ?string
    {
        $collection = $donation->relationLoaded('collection')
            ? $donation->collection
            : $donation->collection()->first();

        $barcode = $collection?->donation_barcode;

        return $barcode === null || $barcode === '' ? null : $barcode;
    }

    /**
     * @return Collection<int, DonationComponent>
     */
    private static function rows(Donation $donation): Collection
    {
        return DonationComponent::query()
            ->with('component')
            ->where('donation_id', $donation->id)
            ->orderBy('id')
            ->get();
    }
}
