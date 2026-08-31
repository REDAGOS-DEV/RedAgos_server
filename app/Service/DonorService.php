<?php

namespace App\Service;

use App\Enums\AccountStatus;
use App\Enums\EligibilityStatus;
use App\Enums\IdentityStatus;
use App\Enums\RoleName;
use App\Models\DonorProfile;
use App\Models\User;
use App\Repository\DonorRepository;
use App\Repository\EligibilityRepository;
use App\Support\AccountIdentity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DonorService
{
    private const DONOR_ROLE = RoleName::Donor->value;

    private const INITIAL_ACCOUNT_STATUS = AccountStatus::PendingVerification;

    /**
     * Where identity documents live on the private disk.
     */
    private const IDENTITY_DOCUMENT_DIRECTORY = 'identity-documents';

    public function __construct(
        private readonly DonorRepository $donorRepository,
        private readonly EligibilityRepository $eligibilityRepository,
        private readonly EligibilityRuleEvaluator $eligibilityRuleEvaluator
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function register(array $payload): array
    {
        $normalizedEmail = Str::lower(trim($payload['email']));
        $normalizedPhone = $this->normalizePhilippinePhone($payload['phone']);

        if ($this->donorRepository->existsEmail($normalizedEmail)) {
            throw ValidationException::withMessages([
                'email' => ['This email address is already registered.'],
            ]);
        }

        if ($this->donorRepository->existsPhone($normalizedPhone)) {
            throw ValidationException::withMessages([
                'phone' => ['This phone number is already registered.'],
            ]);
        }

        $donor = DB::transaction(function () use ($payload, $normalizedEmail, $normalizedPhone): User {
            $bloodType = $this->donorRepository->findBloodTypeByCode($payload['blood_type']);

            if (! $bloodType) {
                throw ValidationException::withMessages([
                    'blood_type' => ['Please select a valid blood type.'],
                ]);
            }

            $donor = $this->donorRepository->createDonor([
                'uuid' => (string) Str::uuid(),
                'first_name' => trim($payload['first_name']),
                'last_name' => trim($payload['last_name']),
                'email' => $normalizedEmail,
                'phone' => $normalizedPhone,
                'username' => $this->buildUsername($normalizedEmail),
                'password' => Hash::make($payload['password']),
                'account_status' => self::INITIAL_ACCOUNT_STATUS,
                'terms_accepted_at' => now(),
            ]);

            $this->donorRepository->createDonorProfile([
                'donor_id' => $donor->id,
                'blood_type_id' => $bloodType->id,
                'gender' => $payload['gender'],
                'birth_date' => $payload['birth_date'],
                'address' => trim($payload['address']),

                // Optional, and deliberately not a submission: a number with no
                // document is nothing an administrator can review. It exists so
                // the counter can find this donor by the ID they present, and
                // identity_status stays unsubmitted until a photo arrives.
                'valid_id_type' => $payload['valid_id_type'] ?? null,
                'valid_id_number' => $payload['valid_id_number'] ?? null,
            ]);

            $role = $this->donorRepository->findOrCreateRoleByName(self::DONOR_ROLE);
            $this->donorRepository->attachRole($donor, $role);

            return $donor;
        });

        $donor->sendEmailVerificationNotification();

        return [
            'message' => 'Donor registration submitted successfully. Please check your email to verify your address.',
            'data' => [
                'user' => $this->formatDonor(
                    $this->donorRepository->loadDonorRegistration($donor)
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $user): array
    {
        $donor = $this->donorRepository->loadDashboardUser($user);
        $profile = $donor->donorProfile;
        $now = now();

        if (! $profile) {
            throw ValidationException::withMessages([
                'donor' => ['The authenticated user does not have a donor profile.'],
            ]);
        }

        $upcomingAppointment = $this->donorRepository->findUpcomingAppointment($donor->id, $now);
        $recentDonations = $this->donorRepository->recentDonations($donor->id);
        $monthlyCounts = $this->donorRepository
            ->monthlyCompletedDonationCounts($donor->id, $now->copy()->subMonths(11)->startOfMonth(), $now->copy()->endOfMonth());

        return [
            'user' => $this->formatDonor($donor),
            'profile' => [
                'donor_code' => 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT),
                'first_name' => $donor->first_name,
                'last_name' => $donor->last_name,
                'email' => $donor->email,
                'contact_number' => $donor->phone,
                'address' => $profile->address,
                'date_of_birth' => $profile->birth_date?->toDateString(),
                'blood_type' => $profile->bloodType?->code,
                'account_status' => $donor->account_status?->value,
            ],
            'eligibility_status' => $this->eligibilityStatus($donor->id)->value,
            'blood_type' => $profile->bloodType?->code,
            'total_donations' => $this->donorRepository->countCompletedDonations($donor->id),
            'upcoming_appointment' => $this->formatAppointment($upcomingAppointment),
            'recent_donations' => $recentDonations->map(fn (object $donation): array => $this->formatDonation($donation))->values(),
            'monthly_trend' => $this->formatMonthlyTrend($monthlyCounts, $now),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(User $user): array
    {
        $donor = $this->donorRepository->loadDashboardUser($user);
        $profile = $donor->donorProfile;

        if (! $profile) {
            throw ValidationException::withMessages([
                'donor' => ['The authenticated user does not have a donor profile.'],
            ]);
        }

        $dashboard = $this->dashboard($donor);

        return [
            'donor_id' => $dashboard['profile']['donor_code'],
            'donor_code' => $dashboard['profile']['donor_code'],
            'full_name' => trim($donor->first_name.' '.$donor->last_name),
            'first_name' => $donor->first_name,
            'last_name' => $donor->last_name,
            'email' => $donor->email,
            // Changing the address revokes verification, so the client needs to
            // see the current state rather than assume it stayed verified.
            'email_verified' => $donor->hasVerifiedEmail(),
            'phone' => $donor->phone,
            'contact_number' => $donor->phone,
            'birth_date' => $profile->birth_date?->toDateString(),
            'date_of_birth' => $profile->birth_date?->toDateString(),
            'blood_type' => $profile->bloodType?->code,
            'address' => $profile->address,
            'avatar_url' => $profile->profile_image_path,
            'eligibility_status' => $dashboard['eligibility_status'],
            'total_donations' => $dashboard['total_donations'],
            'last_donation_date' => $this->lastDonationDate($profile)?->toDateString(),
            'next_eligible_date' => $this->eligibilityRuleEvaluator
                ->nextEligibleDate($this->lastDonationDate($profile))?->toDateString(),
            'notification_preferences' => $profile->notification_preferences ?: $this->defaultNotificationPreferences(),
            'identity' => $this->formatIdentity($donor, $profile),
        ];
    }

    /**
     * Present the donor's own identity submission.
     *
     * The number is shown in full because the donor owns it; the image is given
     * as the path of the authenticated route that streams it, never a signed
     * URL and never the storage path.
     *
     * @return array<string, mixed>
     */
    private function formatIdentity(User $donor, DonorProfile $profile): array
    {
        $status = $profile->identity_status ?? IdentityStatus::Unsubmitted;

        return [
            'status' => $status->value,
            'valid_id_type' => $profile->valid_id_type?->value,
            'valid_id_type_label' => $profile->valid_id_type?->label(),
            'valid_id_number' => $profile->valid_id_number,
            'submitted_at' => $profile->identity_submitted_at?->toIso8601String(),
            'reviewed_at' => $profile->identity_reviewed_at?->toIso8601String(),
            'rejection_reason' => $profile->identity_rejection_reason,
            'submission_version' => (int) $profile->identity_submission_version,
            'image_url' => $profile->valid_id_image_path
                ? '/donors/'.$donor->uuid.'/identity-image'
                : null,
        ];
    }

    /**
     * Store a submitted identity document and queue it for administrator review.
     *
     * The file is written before the transaction and removed again if the
     * transaction fails, so a rolled-back submission never leaves an orphan on
     * disk; the previous document is deleted only once the new path is
     * committed, so a failure never strands the row pointing at a file that has
     * already been removed.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submitIdentity(User $user, array $payload, UploadedFile $image): array
    {
        $donor = $this->donorRepository->loadDashboardUser($user);

        if (! $donor->donorProfile) {
            throw ValidationException::withMessages([
                'donor' => ['The authenticated user does not have a donor profile.'],
            ]);
        }

        $newPath = $image->store(self::IDENTITY_DOCUMENT_DIRECTORY, 'local');

        try {
            $previousPath = DB::transaction(function () use ($donor, $payload, $newPath): ?string {
                // Locked for the same reason the administrator's decision locks
                // it: without this a donor can swap the document out from under
                // a review that is already in progress.
                $locked = $this->donorRepository->lockDonorProfile($donor->id);
                $status = $locked->identity_status ?? IdentityStatus::Unsubmitted;

                if (! $status->acceptsSubmission()) {
                    throw ValidationException::withMessages([
                        'valid_id_image' => ['Your ID has already been verified and cannot be replaced.'],
                    ]);
                }

                $previousPath = $locked->valid_id_image_path;

                $this->donorRepository->updateDonorProfile($locked, [
                    'valid_id_type' => $payload['valid_id_type'],
                    'valid_id_number' => $payload['valid_id_number'],
                    'valid_id_image_path' => $newPath,
                    'identity_status' => IdentityStatus::Pending,
                    'identity_submitted_at' => now(),
                    'identity_submission_version' => $locked->identity_submission_version + 1,
                    'identity_reviewed_at' => null,
                    'identity_reviewed_by' => null,
                    'identity_rejection_reason' => null,
                ]);

                return $previousPath;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($newPath);

            throw $exception;
        }

        if ($previousPath !== null && $previousPath !== $newPath) {
            Storage::disk('local')->delete($previousPath);
        }

        return [
            'message' => 'Your ID has been submitted for review.',
            'data' => $this->profile($donor->refresh()),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateProfile(User $user, array $payload): array
    {
        $donor = $this->donorRepository->loadDashboardUser($user);
        $profile = $donor->donorProfile;
        $bloodType = $this->donorRepository->findBloodTypeByCode($payload['blood_type']);

        if (! $profile || ! $bloodType) {
            throw ValidationException::withMessages([
                'donor' => ['Unable to update this donor profile.'],
            ]);
        }

        $email = Str::lower(trim($payload['email']));

        // A verification link is signed against sha1() of the address it was
        // issued for, so changing the address silently invalidates any link
        // still in the donor's inbox and leaves the new address unproven.
        // Revoke the verification and issue a link for the new address.
        $emailChanged = $email !== $donor->email;

        DB::transaction(function () use ($donor, $profile, $bloodType, $payload, $email, $emailChanged): void {
            $this->donorRepository->updateUser($donor, [
                'first_name' => trim($payload['first_name']),
                'last_name' => trim($payload['last_name']),
                'email' => $email,
                'phone' => $this->normalizePhilippinePhone($payload['phone']),
            ]);

            if ($emailChanged) {
                $this->donorRepository->markEmailUnverified($donor);
            }

            $this->donorRepository->updateDonorProfile($profile, [
                'blood_type_id' => $bloodType->id,
                'birth_date' => $payload['birth_date'],
                'address' => trim($payload['address']),
            ]);
        });

        // Sent after the commit so a rolled-back update never mails a live link.
        if ($emailChanged) {
            $donor->sendEmailVerificationNotification();
        }

        return [
            'message' => $emailChanged
                ? 'Donor profile updated successfully. Please check your new email address to verify it.'
                : 'Donor profile updated successfully.',
            'data' => $this->profile($donor->refresh()),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function updatePassword(User $user, array $payload): array
    {
        if (! Hash::check($payload['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $this->donorRepository->updateUser($user, [
            'password' => Hash::make($payload['password']),
        ]);

        return [
            'message' => 'Password updated successfully.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateNotificationPreferences(User $user, array $payload): array
    {
        $donor = $this->donorRepository->loadDashboardUser($user);
        $profile = $donor->donorProfile;

        if (! $profile) {
            throw ValidationException::withMessages([
                'donor' => ['The authenticated user does not have a donor profile.'],
            ]);
        }

        $preferences = array_merge($this->defaultNotificationPreferences(), $payload);
        $this->donorRepository->updateDonorProfile($profile, [
            'notification_preferences' => $preferences,
        ]);

        return [
            'message' => 'Notification preferences updated successfully.',
            'notification_preferences' => $preferences,
        ];
    }

    /**
     * Store a new profile photo and return its signed URL.
     *
     * @return array<string, mixed>
     */
    public function updateAvatar(User $user, UploadedFile $avatar): array
    {
        $donor = $this->donorRepository->loadDashboardUser($user);
        $profile = $donor->donorProfile;

        if (! $profile) {
            throw ValidationException::withMessages([
                'donor' => ['The authenticated user does not have a donor profile.'],
            ]);
        }

        $previousPath = $profile->profile_image_path;
        $path = $avatar->store('avatars', 'local');

        $this->donorRepository->updateDonorProfile($profile, ['profile_image_path' => $path]);

        if ($previousPath && Storage::disk('local')->exists($previousPath)) {
            Storage::disk('local')->delete($previousPath);
        }

        return [
            'message' => 'Profile photo updated successfully.',
            'avatar_url' => URL::temporarySignedRoute(
                'donors.avatar.show',
                now()->addMinutes(30),
                ['user' => $donor->uuid]
            ),
        ];
    }

    /**
     * Close the donor's account, pseudonymising personal data.
     *
     * Donation records are deliberately retained for clinical traceability;
     * only the identifying fields on the user and profile are cleared.
     *
     * @return array<string, string>
     */
    public function deleteAccount(User $user): array
    {
        $donor = $this->donorRepository->loadDashboardUser($user);
        $profile = $donor->donorProfile;

        // Captured before the transaction clears the column, and deleted only
        // after it commits.
        $identityImagePath = $profile?->valid_id_image_path;

        DB::transaction(function () use ($donor, $profile): void {
            $anonymousSuffix = Str::lower(Str::random(12));

            if ($profile) {
                $this->eligibilityRepository->revokeQrTokens($profile->donor_id);
                $this->donorRepository->updateDonorProfile($profile, [
                    'address' => null,
                    'valid_id_type' => null,
                    'valid_id_number' => null,
                    'valid_id_image_path' => null,
                    'identity_status' => IdentityStatus::Unsubmitted,
                    'identity_submitted_at' => null,
                    'identity_reviewed_at' => null,
                    'identity_reviewed_by' => null,
                    'identity_rejection_reason' => null,
                    'profile_image_path' => null,
                ]);
                $profile->delete();
            }

            $this->donorRepository->updateUser($donor, [
                'first_name' => 'Deleted',
                'last_name' => 'Donor',
                'email' => "deleted-{$anonymousSuffix}@redagos.invalid",
                'phone' => null,
                'username' => "deleted-{$anonymousSuffix}",
                'account_status' => AccountStatus::Deactivated,
            ]);

            $donor->tokens()->delete();
            $donor->delete();
        });

        // After the commit, and never allowed to fail the closure: the account is
        // already closed, so a storage error here is an orphaned file to clean up
        // later rather than a reason to tell the donor their request did not work.
        if ($identityImagePath !== null) {
            try {
                Storage::disk('local')->delete($identityImagePath);
            } catch (Throwable $exception) {
                Log::warning('Could not delete identity document after account closure.', [
                    'donor_id' => $donor->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'message' => 'Your account has been closed. Donation records are retained for traceability.',
        ];
    }

    /**
     * Resolve the donor's current eligibility state from their latest screening.
     */
    private function eligibilityStatus(int $donorId): EligibilityStatus
    {
        $screening = $this->eligibilityRepository->latestScreening($donorId);

        if (! $screening) {
            return EligibilityStatus::Pending;
        }

        if ($screening->result === EligibilityStatus::Eligible && ! $screening->valid_until->isFuture()) {
            return EligibilityStatus::Expired;
        }

        return $screening->result;
    }

    /**
     * Get the donor's last completed donation date, preferring donation records
     * over the denormalised value on the profile.
     */
    private function lastDonationDate(DonorProfile $profile): ?Carbon
    {
        return $this->eligibilityRepository->lastCompletedDonationAt($profile->donor_id)
            ?? $profile->last_donation_date;
    }

    private function normalizePhilippinePhone(string $phone): string
    {
        return AccountIdentity::normalizePhilippinePhone($phone);
    }

    private function buildUsername(string $email): string
    {
        return AccountIdentity::buildUsername($email);
    }

    /**
     * @return array<string, bool>
     */
    private function defaultNotificationPreferences(): array
    {
        return [
            'appointment_reminders' => true,
            'eligibility_renewal' => true,
            'nearby_drives' => false,
            'email_updates' => false,
            'donation_updates' => true,
            'blood_drive_announcements' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDonor(User $donor): array
    {
        return [
            'uuid' => $donor->uuid,
            'first_name' => $donor->first_name,
            'last_name' => $donor->last_name,
            'email' => $donor->email,
            'phone' => $donor->phone,
            'account_status' => $donor->account_status?->value,
            'roles' => $donor->roles->pluck('name')->values(),
            'donor_profile' => [
                'blood_type' => $donor->donorProfile?->bloodType?->code,
                'gender' => $donor->donorProfile?->gender,
                'birth_date' => $donor->donorProfile?->birth_date?->toDateString(),
                'address' => $donor->donorProfile?->address,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatAppointment(?object $appointment): ?array
    {
        if (! $appointment) {
            return null;
        }

        return [
            'id' => $appointment->id,
            'appointment_datetime' => $appointment->appointment_datetime,
            'status' => $appointment->status,
            'appointment_type' => 'booked',
            'facility_name' => $appointment->facility_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDonation(object $donation): array
    {
        return [
            'id' => $donation->id,
            'donation_date' => $donation->donation_date,
            'status' => $donation->status,
            'blood_type' => $donation->blood_type,
            'facility_name' => $donation->facility_name,
            'donation_type' => $donation->appointment_id ? 'booked' : 'walk_in',
        ];
    }

    /**
     * @param  Collection<string, int>  $monthlyCounts
     * @return array<int, array<string, mixed>>
     */
    private function formatMonthlyTrend($monthlyCounts, Carbon $now): array
    {
        $months = [];

        for ($month = 11; $month >= 0; $month--) {
            $date = $now->copy()->subMonths($month);
            $key = $date->format('Y-m');

            $months[] = [
                'key' => $key,
                'month' => $date->format('M'),
                'count' => (int) ($monthlyCounts[$key] ?? 0),
            ];
        }

        return $months;
    }
}
