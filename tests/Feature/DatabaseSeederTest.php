<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\EligibilityQuestion;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BaselineSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * What seeding puts in a database, deployed or not (2026-10-29).
 *
 * What is locked in: the baseline is the roles, blood types, questionnaire
 * and component catalogue, and is safe to run on every deploy; outside local
 * and testing DatabaseSeeder seeds that baseline and nothing else, so no
 * sample centre, made-up drive or "password" account reaches production.
 */
class DatabaseSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'roles' => Role::count(),
            'blood_types' => BloodType::count(),
            'questions' => EligibilityQuestion::count(),
            'components' => BloodComponent::count(),
        ];
    }

    public function test_the_baseline_is_roles_blood_types_the_questionnaire_and_components(): void
    {
        $this->seed(BaselineSeeder::class);

        $this->assertEqualsCanonicalizing(
            array_map(fn (RoleName $role): string => $role->value, RoleName::cases()),
            Role::pluck('name')->all()
        );
        $this->assertSame(8, BloodType::count());
        $this->assertTrue(EligibilityQuestion::query()->exists());
        $this->assertTrue(BloodComponent::query()->where('name', 'Packed RBC')->exists());

        $this->assertSame(0, Facility::count());
        $this->assertSame(0, User::count());
    }

    public function test_the_baseline_can_run_on_every_deploy(): void
    {
        $this->seed(BaselineSeeder::class);
        $first = $this->counts();

        $this->seed(BaselineSeeder::class);

        $this->assertSame($first, $this->counts());
    }

    public function test_outside_local_and_testing_only_the_baseline_is_seeded(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);

            // Run directly rather than through db:seed, as BloodCenterStaffSeederTest
            // does: `--force` skips the production prompt, so the seeder's own
            // check is what must hold.
            $this->app->make(DatabaseSeeder::class)->run();

            $this->assertSame(8, BloodType::count());
            $this->assertSame(0, Facility::count(), "No sample centre in [{$environment}].");
            $this->assertSame(0, MobileEvent::count(), "No sample drive in [{$environment}].");
            $this->assertFalse(User::query()->where('email', 'test@example.com')->exists(), "No test account in [{$environment}].");
        }
    }

    public function test_locally_the_samples_are_seeded_too(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(8, BloodType::count());
        $this->assertTrue(Facility::query()->where('name', 'Sub-National Blood Center')->exists());
        $this->assertTrue(User::query()->where('email', 'test@example.com')->exists());
    }
}
