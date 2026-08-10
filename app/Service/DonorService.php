<?php

namespace App\Service;

use App\Enums\AccountStatus;
use App\Enums\EligibilityStatus;
use App\Enums\RoleName;
use App\Models\DonorProfile;
use App\Models\User;
use App\Repository\DonorRepository;
use App\Repository\EligibilityRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DonorService
{
    private const DONOR_ROLE = RoleName::Donor->value;

    private const INITIAL_ACCOUNT_STATUS = AccountStatus::PendingVerification;

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

        DB::transaction(function () use ($donor, $profile, $bloodType, $payload): void {
            $this->donorRepository->updateUser($donor, [
                'first_name' => trim($payload['first_name']),
                'last_name' => trim($payload['last_name']),
                'email' => Str::lower(trim($payload['email'])),
                'phone' => $this->normalizePhilippinePhone($payload['phone']),
            ]);

            $this->donorRepository->updateDonorProfile($profile, [
                'blood_type_id' => $bloodType->id,
                'birth_date' => $payload['birth_date'],
                'address' => trim($payload['address']),
            ]);
        });

        return [
            'message' => 'Donor profile updated successfully.',
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

        DB::transaction(function () use ($donor, $profile): void {
            $anonymousSuffix = Str::lower(Str::random(12));

            if ($profile) {
                $this->eligibilityRepository->revokeQrTokens($profile->donor_id);
                $this->donorRepository->updateDonorProfile($profile, [
                    'address' => null,
                    'valid_id_number' => null,
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
        $phone = preg_replace('/[\s-]+/', '', $phone) ?? $phone;

        if (str_starts_with($phone, '09')) {
            return '+63'.substr($phone, 1);
        }

        if (str_starts_with($phone, '63')) {
            return '+'.$phone;
        }

        return $phone;
    }

    private function buildUsername(string $email): string
    {
        return Str::before($email, '@').'-'.Str::lower(Str::random(6));
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
