<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * The baseline everywhere. The sample centres, drives and test account only
     * locally and in tests: the account signs in with "password", and the
     * centres and drives would show donors places and dates that are not real.
     */
    public function run(): void
    {
        $this->call(BaselineSeeder::class);

        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'Seeded the baseline only: the sample centres, drives and test account are for local and testing, '
                .'not the ['.app()->environment().'] environment.'
            );

            return;
        }

        $this->call([
            FacilitySeeder::class,
            // After FacilitySeeder: drives hang off a facility, and the seeder
            // warns and skips if none exist yet.
            MobileEventSeeder::class,
        ]);

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'uuid' => (string) Str::uuid(),
                'first_name' => 'Test',
                'last_name' => 'User',
                'username' => 'testuser',
                'password' => Hash::make('password'),
                'account_status' => AccountStatus::Active,
                'email_verified_at' => now(),
                'activated_at' => now(),
            ]
        );
    }
}
