<?php

namespace App\Service;

use App\Enums\RoleName;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Repository\AuthRepository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    public function __construct(
        private readonly AuthRepository $authRepository
    ) {}

    /**
     * Authenticate a user and issue a personal access token.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function login(array $payload): array
    {
        $user = $this->authRepository->findByEmail(Str::lower(trim($payload['email'])));

        if (! $user || ! Hash::check($payload['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $this->guardAccountStatus($user);
        $requestedRole = $this->guardRequestedRole($user, $payload['role'] ?? null);

        return [
            'user' => UserResource::make($user->load(['roles', 'donorProfile.bloodType']))->resolve(),
            'token' => $this->authRepository->issueToken($user, $this->tokenName($requestedRole)),
            'token_type' => 'Bearer',
            'must_verify_email' => ! $user->hasVerifiedEmail(),
        ];
    }

    /**
     * Revoke the access token used for the current request.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $this->authRepository->revokeToken($token);
        }
    }

    /**
     * Revoke every access token belonging to the user.
     */
    public function logoutFromAllDevices(User $user): void
    {
        $this->authRepository->revokeAllTokens($user);
    }

    /**
     * Send a password reset link without revealing whether the address is registered.
     *
     * @return array<string, string>
     */
    public function sendPasswordResetLink(string $email): array
    {
        Password::sendResetLink(['email' => Str::lower(trim($email))]);

        return [
            'message' => 'If this email is registered, a password reset link has been sent.',
        ];
    }

    /**
     * Reset a password using a valid reset token and revoke every existing session.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function resetPassword(array $payload): array
    {
        $status = Password::reset(
            [
                'email' => Str::lower(trim($payload['email'])),
                'password' => $payload['password'],
                'password_confirmation' => $payload['password_confirmation'] ?? $payload['password'],
                'token' => $payload['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();
                $this->authRepository->revokeAllTokens($user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        return [
            'message' => 'Password reset successfully. Please sign in with your new password.',
        ];
    }

    /**
     * Mark a user's email address as verified from a signed verification link.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function verifyEmail(array $payload): array
    {
        $user = $this->authRepository->findById($payload['id']);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), (string) $payload['hash'])) {
            throw new HttpResponseException(response()->json([
                'message' => 'This verification link is invalid.',
                'code' => 'invalid_verification_link',
            ], 403));
        }

        if ($user->hasVerifiedEmail()) {
            return ['already_verified' => true];
        }

        $this->authRepository->markVerified($user);

        return ['already_verified' => false];
    }

    /**
     * Re-send the verification email when the address is still unverified.
     */
    public function resendVerificationEmail(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->sendEmailVerificationNotification();
    }

    /**
     * Reject sign-in for accounts an administrator has taken out of service.
     */
    private function guardAccountStatus(User $user): void
    {
        $status = $user->account_status;

        if ($status->canAuthenticate()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => $status->authenticationBlockedMessage(),
            'code' => $status->authenticationBlockedCode(),
        ], 403));
    }

    /**
     * Ensure the user actually holds the role their sign-in form claimed.
     */
    private function guardRequestedRole(User $user, ?string $requestedRole): ?RoleName
    {
        $role = RoleName::normalize($requestedRole);

        if ($role === null) {
            return null;
        }

        if (! $user->hasRole($role)) {
            throw new HttpResponseException(response()->json([
                'message' => 'This account cannot sign in from this portal.',
                'code' => 'role_mismatch',
            ], 403));
        }

        return $role;
    }

    /**
     * Build the personal access token name, tagged with the portal used to sign in.
     */
    private function tokenName(?RoleName $role): string
    {
        return $role === null ? 'api-token' : "{$role->value}-token";
    }
}
