<?php

namespace App\Support;

/**
 * Where a component's bags have to be kept (config blood_center.storage_areas).
 *
 * Stock Intake offers a bag only these shelves (project owner, 2026-11-27), so
 * red cells are not put in a freezer or platelets in a refrigerator.
 */
final class StorageAreas
{
    /**
     * The locations a component's bags may be shelved in, or null when no area
     * names the component, so a centre's own component is offered every
     * location rather than none.
     *
     * @return array<int, string>|null
     */
    public static function locationsFor(?string $componentName): ?array
    {
        $wanted = mb_strtolower(trim((string) $componentName));

        if ($wanted === '') {
            return null;
        }

        foreach ((array) config('blood_center.storage_areas', []) as $area) {
            $names = array_map(
                fn ($name): string => mb_strtolower(trim((string) $name)),
                (array) ($area['components'] ?? [])
            );

            if (in_array($wanted, $names, true)) {
                return array_values(array_map('strval', (array) ($area['locations'] ?? [])));
            }
        }

        return null;
    }
}
