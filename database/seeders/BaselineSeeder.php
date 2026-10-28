<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\BloodType;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The rows every environment needs before anyone can use it, production included.
 *
 * Roles (no account can be given one that does not exist), the eight blood
 * types, every questionnaire version, and the component catalogue. Nothing
 * else: no facility, drive or account. Those are created in the portal, or by
 * `facility:create` and `admin:create`.
 *
 * Idempotent, so a deploy may run it every time:
 * `php artisan db:seed --class=BaselineSeeder --force`.
 */
class BaselineSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            EligibilityQuestionSeeder::class,
            BloodComponentSeeder::class,
        ]);

        foreach (RoleName::cases() as $role) {
            Role::firstOrCreate(['name' => $role->value]);
        }

        foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bloodType) {
            BloodType::firstOrCreate([
                'code' => $bloodType,
                'label' => $bloodType,
            ]);
        }
    }
}
