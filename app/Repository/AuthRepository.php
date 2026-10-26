<?php

namespace App\Repository;

use App\Enums\AccountStatus;
use App\Enums\RoleName;
use App\Models\User;
use App\Support\AccountIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
     * Find the account a sign-in names: by email address, then donor code,
     * then username.
     *
     * The address is matched lower-cased, as it is stored. The donor code
     * (DONOR-000015, from the counter's sign-in slip) is matched in any case,
     * and only ever names a donor: a staff account's id is not a sign-in name.
     * The username is matched exactly as stored, because its unique index is
     * case-sensitive on PostgreSQL and a case-blind match could find two
     * accounts. Trying the address first means a username that happens to
     * equal somebody's address can never stand in for that person's account.
     */
    public function findByLogin(string $login): ?User
    {
        return $this->findByEmail(Str::lower($login))
            ?? $this->findByDonorCode($login)
            ?? User::with('roles')->where('username', $login)->first();
    }

    private function findByDonorCode(string $code): ?User
    {
        $donorId = AccountIdentity::donorIdFromCode($code);

        if ($donorId === null) {
            return null;
        }

        return User::with('roles')
            ->whereKey($donorId)
            ->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', RoleName::Donor->value))
            ->first();
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
     * The donor has replaced the counter's temporary password with their own,
     * so the account is theirs now.
     *
     * It becomes active, which is also what stops the counter correcting its
     * contact details (DonorDirectoryService::editable()). Every other session
     * ends, so one opened with the slip by someone else cannot outlive the
     * change.
     */
    public function claimCounterAccount(User $user, mixed $currentToken): void
    {
        $user->forceFill([
            'password_change_required' => false,
            'account_status' => $user->account_status === AccountStatus::PendingVerification
                ? AccountStatus::Active
                : $user->account_status,
            'activated_at' => $user->activated_at ?? now(),
        ])->save();

        $user->tokens()
            ->when(
                $currentToken instanceof PersonalAccessToken,
                fn ($tokens) => $tokens->whereKeyNot($currentToken->getKey())
            )
            ->delete();
    }

    /**
     * Put a counter-issued temporary password on a donor's account.
     *
     * Every session ends: a reissued slip replaces the last one, and whoever
     * held it is signed out. sign_in_issued_at keeps the first issue.
     */
    public function issueTemporaryPassword(User $user, string $password): void
    {
        $user->forceFill([
            'password' => Hash::make($password),
            'password_change_required' => true,
            'sign_in_issued_at' => $user->sign_in_issued_at ?? now(),
        ])->save();

        $this->revokeAllTokens($user);
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
