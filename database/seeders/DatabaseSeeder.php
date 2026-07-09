<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\BloodType;
use App\Models\Role;
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
        foreach (['admin', 'donor', 'blood_center', 'blood_bank'] as $role) {
            Role::firstOrCreate(['name' => $role]);
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
            ]
        );
    }
}
