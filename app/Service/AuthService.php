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
        private readonly AuthRepository $authRepository,
        private readonly AuditLogger $auditLogger,
        private readonly GoogleIdTokenVerifier $googleVerifier
    ) {}

    /**
     * Authenticate a user and issue a personal access token.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function login(array $payload): array
    {
        // `login` (an address or a username) from the single sign-in page,
        // `email` from the pages that still send it. The refusal is filed
        // under whichever field was sent, so the form can mark it.
        $field = filled($payload['login'] ?? null) ? 'login' : 'email';
        $user = $this->authRepository->findByLogin(trim((string) $payload[$field]));

        if (! $user || ! Hash::check($payload['password'], $user->password)) {
            // An address that matches no account belongs to no facility, so
            // only a wrong password on a real account is recorded.
            if ($user) {
                $this->recordFailedSignIn($user, 'wrong_password');
            }

            throw ValidationException::withMessages([
                $field => ['The provided credentials are incorrect.'],
            ]);
        }

        return $this->openSession($user, $payload['role'] ?? null);
    }

    /**
     * Sign in with Google (2026-10-30): the credential Google Identity Services
     * gave the browser, checked with Google, for the account that already uses
     * that address. Google signs nobody up; a donor registers first.
     *
     * @return array<string, mixed>
     */
    public function loginWithGoogle(string $credential): array
    {
        $email = $this->googleVerifier->verifiedEmail($credential);
        $user = $this->authRepository->findByLogin($email);

        if (! $user) {
            throw new HttpResponseException(response()->json([
                'message' => 'No RedAgos account uses this Google address. Register as a donor, or sign in with your password.',
                'code' => 'google_account_not_found',
            ], 404));
        }

        // Google has just confirmed the address, which is what the emailed
        // link proves, so an unverified one does not stop this sign-in.
        return $this->openSession($user, null, 'google');
    }

    /**
     * The checks every sign-in passes, then the token.
     *
     * @return array<string, mixed>
     */
    private function openSession(User $user, ?string $role, string $via = 'password'): array
    {
        try {
            $this->guardAccountStatus($user);
            if ($via !== 'google') {
                $this->guardEmailVerified($user);
            }
            $requestedRole = $this->guardRequestedRole($user, $role);
        } catch (HttpResponseException $refused) {
            // The right password on an account that may not sign in (a
            // deactivated colleague, the wrong portal) is the attempt an
            // Admin most wants to see.
            $body = json_decode((string) $refused->getResponse()->getContent(), true);
            $this->recordFailedSignIn($user, is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : 'refused');

            throw $refused;
        }

        $this->auditLogger->record($user, 'auth.signed_in', $user, array_filter([
            'portal' => $requestedRole?->value,
            'via' => $via === 'password' ? null : $via,
        ]));

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

        $this->auditLogger->record($user, 'auth.signed_out', $user);
    }

    /**
     * Revoke every access token belonging to the user.
     */
    public function logoutFromAllDevices(User $user): void
    {
        $this->authRepository->revokeAllTokens($user);

        $this->auditLogger->record($user, 'auth.signed_out', $user, ['all_devices' => true]);
    }

    /**
     * Send a password reset link without revealing whether the address is registered.
     *
     * Only to a confirmed address. A counter-registered donor's address was
     * typed by staff, and a reset link mailed to a mistyped one would hand the
     * account to whoever owns it. Such a donor confirms it from the portal, or
     * asks the counter for a new sign-in slip. The reply is the same either
     * way, so it still reveals nothing.
     *
     * @return array<string, string>
     */
    public function sendPasswordResetLink(string $email): array
    {
        $normalized = Str::lower(trim($email));

        if ($this->authRepository->findByEmail($normalized)?->hasVerifiedEmail()) {
            Password::sendResetLink(['email' => $normalized]);
        }

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
                // A password the donor chose replaces any the counter issued.
                $user->forceFill(['password' => $password, 'password_change_required' => false])->save();
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
     * Record a refused sign-in against the account it was made on.
     *
     * No actor: nobody is signed in, and whoever typed the password may not be
     * the account's owner. The account is the subject, and its facility files
     * the row where its Admin will see it. The reason is a code; the address
     * and the password typed are never written.
     */
    private function recordFailedSignIn(User $user, string $reason): void
    {
        $this->auditLogger->record(null, 'auth.sign_in_failed', $user, array_filter([
            'facility_id' => $user->facility_id,
            'reason' => $reason,
        ], fn (mixed $value): bool => $value !== null));
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
     *
     * A donor given a sign-in slip at the counter is let through without one:
     * staff checked their ID in person, which proves more than a link in an
     * inbox, and the donor may have no address at all. They confirm it from
     * the portal later, which online booking still asks for.
     */
    private function guardEmailVerified(User $user): void
    {
        if ($user->hasVerifiedEmail() || $user->sign_in_issued_at !== null) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Please verify your email address before signing in. Check your inbox for the verification link.',
            'code' => 'email_not_verified',
        ], 403));
    }

    /**
     * Ensure the user actually holds the role their sign-in form claimed.
     *
     * The single sign-in page names no role, so an empty one is checked by
     * guardUnifiedSignIn() rather than waved through. The administrator page
     * still names `admin`.
     */
    private function guardRequestedRole(User $user, ?string $requestedRole): ?RoleName
    {
        $role = RoleName::normalize($requestedRole);

        if ($role === null) {
            return $this->guardUnifiedSignIn($user);
        }

        if ($user->hasRole($role)) {
            return $role;
        }

        // A handful of blood centres applied through the public registration
        // flow that has since been removed, and hold no role until an
        // administrator resolves their record. There is no status screen and
        // nothing to resubmit any more, so the refusal says so plainly rather
        // than issuing a roleless token that can reach nothing.
        if ($this->isUnapprovedOrganisationApplicant($user, $role)) {
            throw $this->facilityNotActivated();
        }

        throw new HttpResponseException(response()->json([
            'message' => 'This account cannot sign in from this portal.',
            'code' => 'role_mismatch',
        ], 403));
    }

    /**
     * Admit a sign-in from the single sign-in page, which names no role.
     *
     * Donors, blood-centre staff and hospital blood banks all sign in there,
     * and the client sends each to their own dashboard by the roles on the
     * account. Administrators sign in there too (project owner, 2026-10-30;
     * they had their own page before) and are sent to the admin portal.
     * Accounts with no role at all are turned away: they would otherwise be
     * handed a token that can reach nothing.
     *
     * Returns the account's role when it holds exactly one, so the audit row
     * and the token name say which portal it signed in to, as they do when a
     * page names it. An account with several has no single answer.
     */
    private function guardUnifiedSignIn(User $user): ?RoleName
    {
        if ($user->roles->isEmpty()) {
            if ($this->hasUnactivatedFacility($user)) {
                throw $this->facilityNotActivated();
            }

            throw new HttpResponseException(response()->json([
                'message' => 'This account has no access to RedAgos yet. Please contact the RedAgos administrator.',
                'code' => 'no_portal_access',
            ], 403));
        }

        return $user->roles->count() === 1
            ? RoleName::tryFrom($user->roles->first()->name)
            : null;
    }

    /**
     * Determine whether this is a legacy organisation applicant whose facility
     * was never activated.
     *
     * Deliberately narrow: only the two organisation roles qualify, so a donor
     * signing in through the wrong portal still gets the generic refusal.
     */
    private function isUnapprovedOrganisationApplicant(User $user, RoleName $role): bool
    {
        if (! in_array($role, [RoleName::BloodCenter, RoleName::BloodBank], true)) {
            return false;
        }

        return $this->hasUnactivatedFacility($user);
    }

    /**
     * Whether the account's facility is still pending or was rejected: the
     * legacy applicants, who hold no role until an administrator resolves them.
     */
    private function hasUnactivatedFacility(User $user): bool
    {
        return $user->facility !== null && in_array(
            $user->facility->status,
            [FacilityStatus::PendingApproval, FacilityStatus::Rejected],
            true
        );
    }

    private function facilityNotActivated(): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => 'This facility account has not been activated yet. Please contact the RedAgos administrator.',
            'code' => 'facility_not_activated',
        ], 403));
    }

    /**
     * Build the personal access token name, tagged with the portal used to sign in.
     */
    private function tokenName(?RoleName $role): string
    {
        return $role === null ? 'api-token' : "{$role->value}-token";
    }
}
