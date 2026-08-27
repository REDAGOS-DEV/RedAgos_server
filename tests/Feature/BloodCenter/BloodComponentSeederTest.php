<?php

namespace Tests\Feature\BloodCenter;

use App\Models\BloodComponent;
use Database\Seeders\BloodComponentSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BloodComponentSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_seeds_the_components_the_frontend_uses(): void
    {
        $this->seed(BloodComponentSeeder::class);

        foreach (['Whole Blood', 'Packed RBC', 'Fresh Frozen Plasma', 'Platelets', 'Cryoprecipitate'] as $name) {
            $this->assertDatabaseHas('blood_components', ['name' => $name]);
        }

        $this->assertSame(5, BloodComponent::count());
    }

    public function test_re_running_it_does_not_duplicate(): void
    {
        $this->seed(BloodComponentSeeder::class);
        $this->seed(BloodComponentSeeder::class);

        $this->assertSame(5, BloodComponent::count());
    }

    public function test_it_restores_a_soft_deleted_component_instead_of_colliding(): void
    {
        $this->seed(BloodComponentSeeder::class);

        BloodComponent::where('name', 'Platelets')->firstOrFail()->delete();
        $this->assertSame(4, BloodComponent::count());

        // updateOrCreate() would not see the trashed row, would fall through to
        // an insert, and would hit the unique name index.
        $this->seed(BloodComponentSeeder::class);

        $this->assertSame(5, BloodComponent::count());
        $this->assertDatabaseHas('blood_components', ['name' => 'Platelets', 'deleted_at' => null]);
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
}
