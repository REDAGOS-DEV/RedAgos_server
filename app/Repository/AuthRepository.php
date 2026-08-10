<?php

namespace App\Repository;

use App\Enums\AccountStatus;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class AuthRepository
{
    /**
     * Find an active (non soft-deleted) user by email address.
     */
    public function findByEmail(string $email): ?User
    {
        return User::with('roles')->where('email', $email)->first();
    }

    /**
     * Find a user by primary key regardless of verification state.
     */
    public function findById(int|string $id): ?User
    {
        return User::find($id);
    }

    /**
     * Issue a personal access token and return its plain text value.
     */
    public function issueToken(User $user, string $name): string
    {
        return $user->createToken($name)->plainTextToken;
    }

    /**
     * Revoke a single personal access token.
     */
    public function revokeToken(PersonalAccessToken $token): void
    {
        $token->delete();
    }

    /**
     * Revoke every personal access token belonging to the user.
     */
    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Mark the user as verified and activated.
     */
    public function markVerified(User $user): User
    {
        $user->forceFill([
            'email_verified_at' => now(),
            'account_status' => AccountStatus::Active,
            'activated_at' => $user->activated_at ?? now(),
        ])->save();

        return $user;
    }
}
