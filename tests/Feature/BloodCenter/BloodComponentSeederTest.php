<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\IndicationCode;
use App\Models\BloodComponent;
use Database\Seeders\BloodComponentSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BloodComponentSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_seeds_the_components_the_frontend_uses(): void
    {
        $this->seed(BloodComponentSeeder::class);

        // The six the DOH Blood Request Form prints indication codes for, plus
        // Cryosupernate, which the Daily Blood Stock Inventory sheet reports.
        // Platelets are named as that sheet names them.
        $components = [
            'Whole Blood', 'Packed RBC', 'Fresh Frozen Plasma',
            'Platelet Concentrate', 'Cryoprecipitate', 'Washed RBC', 'Cryosupernate',
        ];

        foreach ($components as $name) {
            $this->assertDatabaseHas('blood_components', ['name' => $name]);
        }

        $this->assertSame(7, BloodComponent::count());
        $this->assertDatabaseMissing('blood_components', ['name' => 'Platelets']);
    }

    public function test_re_running_it_does_not_duplicate(): void
    {
        $this->seed(BloodComponentSeeder::class);
        $this->seed(BloodComponentSeeder::class);

        $this->assertSame(7, BloodComponent::count());
    }

    public function test_it_restores_a_soft_deleted_component_instead_of_colliding(): void
    {
        $this->seed(BloodComponentSeeder::class);

        BloodComponent::where('name', 'Platelet Concentrate')->firstOrFail()->delete();
        $this->assertSame(6, BloodComponent::count());

        // updateOrCreate() would not see the trashed row, would fall through to
        // an insert, and would hit the unique name index.
        $this->seed(BloodComponentSeeder::class);

        $this->assertSame(7, BloodComponent::count());
        $this->assertDatabaseHas('blood_components', ['name' => 'Platelet Concentrate', 'deleted_at' => null]);
    }

    public function test_it_leaves_clinical_values_null(): void
    {
        $this->seed(BloodComponentSeeder::class);

        // Shelf life drives unit expiry and must come from a named clinical
        // owner, never from a table shipped in source.
        $this->assertSame(0, BloodComponent::whereNotNull('shelf_life_days')->count());
    }

    public function test_it_never_overwrites_a_configured_shelf_life(): void
    {
        $this->seed(BloodComponentSeeder::class);

        BloodComponent::where('name', 'Packed RBC')->update([
            'shelf_life_days' => 42,
            'storage_temperature' => '1-6 C',
        ]);

        $this->seed(BloodComponentSeeder::class);

        $this->assertSame(42, BloodComponent::where('name', 'Packed RBC')->firstOrFail()->shelf_life_days);
    }

    public function test_every_indication_code_names_a_seeded_component(): void
    {
        $this->seed(BloodComponentSeeder::class);

        // P1-P6 are looked up by name. A rename that missed IndicationCode
        // would leave platelets unrequestable.
        foreach (IndicationCode::cases() as $code) {
            $this->assertDatabaseHas('blood_components', ['name' => $code->componentName()]);
        }
    }

    /**
     * The catalogue migration, re-run against a catalogue seeded the old way.
     */
    public function test_the_catalogue_migration_renames_platelets_and_adds_cryosupernate(): void
    {
        $platelets = BloodComponent::create(['name' => 'Platelets']);
        BloodComponent::create(['name' => 'Packed RBC']);

        $migration = require database_path('migrations/2026_09_27_000001_add_cryosupernate_and_rename_platelets.php');
        $migration->up();

        // Renamed in place: units and settings point at the id, which is kept.
        $this->assertSame('Platelet Concentrate', $platelets->fresh()->name);
        $this->assertDatabaseHas('blood_components', ['name' => 'Cryosupernate', 'deleted_at' => null]);

        // Safe to run again.
        $migration->up();
        $this->assertSame(1, DB::table('blood_components')->where('name', 'Cryosupernate')->count());
        $this->assertSame(3, BloodComponent::count());
    }

    public function test_the_catalogue_migration_leaves_a_fresh_database_to_the_seeder(): void
    {
        $migration = require database_path('migrations/2026_09_27_000001_add_cryosupernate_and_rename_platelets.php');
        $migration->up();

        $this->assertSame(0, BloodComponent::withTrashed()->count());
    }
}
