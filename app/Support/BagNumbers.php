<?php

namespace App\Support;

use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\Facility;
use Illuminate\Support\Collection;

/**
 * Each bag's number: [centre]-[barcode]-[component], SNBC-21627990-PRBC.
 *
 * One format for every bag a RedAgos blood centre books (project owner,
 * 2026-11-13): the abbreviation of the centre that collected the donation,
 * the donation's pre-printed barcode sticker, and the component's code. Every
 * bag from one donation carries the same sticker, so a second bag of the same
 * component ends -2: SNBC-21627990-PRBC-2. The number is on the label
 * Processing prints, and it becomes the unit's id when Issuance books the bag
 * in — one number from the bench to the patient.
 *
 * Computed on read and never stored. The barcode can still be corrected until
 * the first bag is booked in, and a stored copy would go stale; this cannot.
 *
 * There is no other numbering. A donation without a barcode, or from a centre
 * without an abbreviation, has no bag numbers and its bags cannot be booked
 * until it has both. Bags a hospital receives from outside RedAgos keep the
 * DD-… ids their delivery gives them (DirectDistribution).
 */
final class BagNumbers
{
    /**
     * A bag number, or the start of one: an optional centre, the sticker's
     * digits, the component code, and the -2, -3… of a repeated component.
     * The centre is optional so a number typed without it still finds its
     * donation.
     */
    private const PATTERN = '/^(?:([A-Z]{2,6})-)?(\d+)-([A-Z][A-Z0-9]*)(?:-(\d+))?$/';

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
        $prefix = self::prefix($donation);
        $seen = [];
        $slots = [];

        foreach (self::rows($donation) as $row) {
            $componentId = (int) $row->component_id;
            $count = max(1, (int) $row->quantity);

            for ($i = 0; $i < $count; $i++) {
                $seen[$componentId] = ($seen[$componentId] ?? 0) + 1;

                $slots[$componentId][] = [
                    'row_id' => (int) $row->id,
                    'bag_number' => $prefix === null
                        ? null
                        : self::compose($prefix, $row->component?->labelCode() ?? 'X', $seen[$componentId]),
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
     * The abbreviation of the centre that collected the donation, or null.
     *
     * Read without loading the relation onto the donation, which callers go on
     * to serialise.
     */
    public static function centre(Donation $donation): ?string
    {
        $abbreviation = $donation->relationLoaded('facility')
            ? $donation->facility?->abbreviation
            : Facility::withTrashed()->whereKey($donation->facility_id)->value('abbreviation');

        return $abbreviation === null || $abbreviation === '' ? null : $abbreviation;
    }

    /**
     * Why a donation's bags have no numbers, or null when they have them.
     *
     * @return 'barcode_missing'|'centre_abbreviation_missing'|null
     */
    public static function missing(Donation $donation): ?string
    {
        return match (true) {
            self::barcode($donation) === null => 'barcode_missing',
            self::centre($donation) === null => 'centre_abbreviation_missing',
            default => null,
        };
    }

    /**
     * What a scanner leaves or a person types, as a bag number is written:
     * scanner control characters and spaces stripped, in capitals.
     */
    public static function normalise(string $term): string
    {
        return strtoupper((string) preg_replace('/[\s\p{Cc}]+/u', '', $term));
    }

    /**
     * The parts of a bag number, or null when the text is not one.
     *
     * @return array{centre: string|null, barcode: string, component: string, sequence: int}|null
     */
    public static function parse(string $term): ?array
    {
        if (preg_match(self::PATTERN, self::normalise($term), $m) !== 1) {
            return null;
        }

        return [
            'centre' => ($m[1] ?? '') === '' ? null : $m[1],
            'barcode' => $m[2],
            'component' => $m[3],
            'sequence' => isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 1,
        ];
    }

    /**
     * The donation barcode a scanned or typed code names at a centre, or null.
     *
     * A scanned sticker is its own barcode; a full bag number gives the
     * barcode inside it. A bag number from another centre names none of this
     * centre's donations, even when its sticker happens to share the digits:
     * stickers are unique per centre, not across them.
     */
    public static function barcodeFrom(string $term, ?string $centre): ?string
    {
        $code = self::normalise($term);

        if ($code === '') {
            return null;
        }

        if (ctype_digit($code)) {
            return $code;
        }

        $parts = self::parse($code);

        if ($parts === null || ($parts['centre'] !== null && $centre !== null && $parts['centre'] !== $centre)) {
            return null;
        }

        return $parts['barcode'];
    }

    /**
     * The abbreviation of a facility, for barcodeFrom().
     */
    public static function centreOf(int $facilityId): ?string
    {
        $abbreviation = Facility::withTrashed()->whereKey($facilityId)->value('abbreviation');

        return $abbreviation === null || $abbreviation === '' ? null : $abbreviation;
    }

    /**
     * SNBC-21627990 for a donation with both parts, else null.
     */
    private static function prefix(Donation $donation): ?string
    {
        $barcode = self::barcode($donation);
        $centre = $barcode === null ? null : self::centre($donation);

        return $barcode === null || $centre === null ? null : $centre.'-'.$barcode;
    }

    /**
     * The n-th bag of a component: SNBC-21627990-PRBC, then SNBC-21627990-PRBC-2.
     */
    private static function compose(string $prefix, string $code, int $n): string
    {
        return $prefix.'-'.$code.($n > 1 ? '-'.$n : '');
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
