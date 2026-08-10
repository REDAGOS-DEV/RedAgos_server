<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\RoleName;
use App\Models\BloodType;
use App\Models\Role;
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
     */
    public function run(): void
    {
        $this->call([
            EligibilityQuestionSeeder::class,
            FacilitySeeder::class,
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
