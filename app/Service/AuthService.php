<?php

namespace App\Service;

use App\Enums\FacilityStatus;
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
        $this->guardEmailVerified($user);
        $requestedRole = $this->guardRequestedRole($user, $payload['role'] ?? null);

        return [
            'user' => UserResource::make($user->load(['roles', 'donorProfile.bloodType', 'facility']))->resolve(),
            'token' => $this->authRepository->issueToken($user, $this->tokenName($requestedRole)),
            'token_type' => 'Bearer',
            // Always false now that guardEmailVerified() refuses an unverified
            // address outright. Kept so existing clients reading it keep
            // working; nothing needs to act on it any more.
            'must_verify_email' => false,
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
     * Re-send the verification email to an address supplied by a guest.
     *
     * Sign-in is refused until the address is verified, so the authenticated
     * resend endpoint is unreachable for exactly the people who need it most —
     * someone who registered and never received the mail. This is the way back
     * in for them.
     *
     * It deliberately reports nothing about the address: unknown, already
     * verified and freshly sent all look identical to the caller, so the
     * endpoint cannot be used to enumerate registered accounts.
     */
    public function resendVerificationEmailToAddress(string $email): void
    {
        $user = $this->authRepository->findByEmail(Str::lower(trim($email)));

        if (! $user || $user->hasVerifiedEmail() || ! $user->account_status->canAuthenticate()) {
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
     * Refuse sign-in until the email address on the account has been verified.
     *
     * Verification is part of registration now, not something deferred until
     * the first booking attempt, so an unverified account has no business
     * holding a token at all. The `code` lets the client offer a resend
     * instead of showing the generic credentials error.
     */
    private function guardEmailVerified(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Please verify your email address before signing in. Check your inbox for the verification link.',
            'code' => 'email_not_verified',
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

        if ($user->hasRole($role)) {
            return $role;
        }

        // An organisation awaiting approval, or one that was rejected, holds no
        // role yet by design. Refusing outright would leave it unable to read
        // its own status or resubmit, so authentication succeeds and a generic
        // token is issued. The role is still genuinely absent, so the role:
        // middleware keeps refusing every protected route — authentication
        // succeeds, authorization does not.
        if ($this->isUnapprovedOrganisationApplicant($user, $role)) {
            return null;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'This account cannot sign in from this portal.',
            'code' => 'role_mismatch',
        ], 403));
    }

    /**
     * Determine whether this is an organisation applicant still awaiting a
     * decision on its facility.
     *
     * Deliberately narrow: only the two organisation roles qualify, so a donor
     * signing in through the wrong portal is still refused.
     */
    private function isUnapprovedOrganisationApplicant(User $user, RoleName $role): bool
    {
        if (! in_array($role, [RoleName::BloodCenter, RoleName::BloodBank], true)) {
            return false;
        }

        return $user->facility !== null && in_array(
            $user->facility->status,
            [FacilityStatus::PendingApproval, FacilityStatus::Rejected],
            true
        );
    }

    /**
     * Build the personal access token name, tagged with the portal used to sign in.
     */
    private function tokenName(?RoleName $role): string
    {
        return $role === null ? 'api-token' : "{$role->value}-token";
    }
}
