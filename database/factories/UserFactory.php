<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\RoleName;
use App\Models\DonorProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'uuid' => (string) Str::uuid(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'phone' => '+639'.fake()->unique()->numerify('#########'),
            'username' => fake()->unique()->userName(),
            'password' => static::$password ??= Hash::make('password'),
            'account_status' => AccountStatus::Active,
            'activated_at' => now(),
            'terms_accepted_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
            'account_status' => AccountStatus::PendingVerification,
            'activated_at' => null,
        ]);
    }

    /**
     * Indicate that the account has been suspended by an administrator.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_status' => AccountStatus::Suspended,
        ]);
    }

    /**
     * Indicate that the account has been deactivated.
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_status' => AccountStatus::Deactivated,
        ]);
    }

    /**
     * Attach a canonical application role once the user has been created.
     */
    public function withRole(RoleName $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            $user->roles()->syncWithoutDetaching([
                Role::firstOrCreate(['name' => $role->value])->id,
            ]);
        });
    }

    /**
     * Create a donor: the donor role plus the donor profile the API requires.
     */
    public function donor(): static
    {
        return $this->withRole(RoleName::Donor)
            ->afterCreating(function (User $user): void {
                DonorProfile::factory()->create(['donor_id' => $user->id]);
            });
    }
}
