<?php

namespace Database\Seeders;

use App\Models\BloodComponent;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BloodComponentSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The components the blood-centre and hospital screens already reference.
     *
     * Names only. shelf_life_days and storage_temperature are deliberately left
     * NULL: they drive unit expiry, and shelf life varies by preparation,
     * anticoagulant and storage protocol, so they must come from a named
     * clinical owner rather than a generic table shipped in source. Module 2
     * fails loudly on a NULL rather than defaulting.
     *
     * @var array<int, string>
     */
    private const COMPONENTS = [
        'Whole Blood' => 'WB',
        'Packed RBC' => 'PRBC',
        'Fresh Frozen Plasma' => 'FFP',
        'Platelet Concentrate' => 'PC',
        'Cryoprecipitate' => 'CRYO',
        'Washed RBC' => 'WRBC',
        'Cryosupernate' => 'CSP',
    ];

    public function run(): void
    {
        foreach (self::COMPONENTS as $name => $code) {
            // blood_components soft-deletes AND has a unique name, so
            // updateOrCreate() would fail to see a trashed row, fall through to
            // an insert, and collide with the unique index. Going through
            // withTrashed() restores instead, preserving whatever clinical
            // values that row already carried.
            $component = BloodComponent::withTrashed()->firstOrNew(['name' => $name]);

            // The bag-number code is not clinical, so it is filled in when
            // missing — never overwritten if a centre changed it.
            $component->code ??= $code;

            if ($component->trashed()) {
                $component->restore();

                continue;
            }

            // Never writes shelf_life_days or storage_temperature, so re-running
            // the seeder cannot clobber clinically supplied values.
            $component->save();
        }
    }
}
