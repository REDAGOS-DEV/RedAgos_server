<?php

namespace App\Repository;

use App\Models\BloodType;
use App\Models\DonorProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DonorRepository
{
    public function createDonor(array $payload): User
    {
        return User::create($payload);
    }

    public function createDonorProfile(array $payload): DonorProfile
    {
        return DonorProfile::create($payload);
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function findByPhone(string $phone): ?User
    {
        return User::where('phone', $phone)->first();
    }

    public function existsEmail(string $email): bool
    {
        return User::where('email', $email)->exists();
    }

    public function existsPhone(string $phone): bool
    {
        return User::where('phone', $phone)->exists();
    }

    public function findBloodTypeByCode(string $code): ?BloodType
    {
        return BloodType::where('code', $code)->first();
    }

    public function findOrCreateRoleByName(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name]);
    }

    public function attachRole(User $user, Role $role): void
    {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    public function loadDonorRegistration(User $user): User
    {
        return $user->load(['roles', 'donorProfile.bloodType']);
    }

    public function loadDashboardUser(User $user): User
    {
        return $user->load(['roles', 'donorProfile.bloodType']);
    }

    public function updateUser(User $user, array $payload): User
    {
        $user->update($payload);

        return $user;
    }

    public function updateDonorProfile(DonorProfile $profile, array $payload): DonorProfile
    {
        $profile->update($payload);

        return $profile;
    }

    public function countCompletedDonations(int $donorId): int
    {
        return DB::table('donations')
            ->where('donor_id', $donorId)
            ->where('status', 'completed')
            ->count();
    }

    public function findUpcomingAppointment(int $donorId, Carbon $now): ?object
    {
        return DB::table('donation_appointments')
            ->join('facilities', 'facilities.id', '=', 'donation_appointments.facility_id')
            ->where('donation_appointments.donor_id', $donorId)
            ->whereIn('donation_appointments.status', ['scheduled', 'confirmed'])
            ->where('donation_appointments.appointment_datetime', '>=', $now)
            ->orderBy('donation_appointments.appointment_datetime')
            ->select([
                'donation_appointments.id',
                'donation_appointments.appointment_datetime',
                'donation_appointments.status',
                'facilities.name as facility_name',
            ])
            ->first();
    }

    public function recentDonations(int $donorId, int $limit = 5): Collection
    {
        return DB::table('donations')
            ->join('facilities', 'facilities.id', '=', 'donations.facility_id')
            ->leftJoin('donor_profiles', 'donor_profiles.donor_id', '=', 'donations.donor_id')
            ->leftJoin('blood_types', 'blood_types.id', '=', 'donor_profiles.blood_type_id')
            ->where('donations.donor_id', $donorId)
            ->latest('donations.donation_date')
            ->limit($limit)
            ->select([
                'donations.id',
                'donations.donation_date',
                'donations.status',
                'donations.appointment_id',
                'facilities.name as facility_name',
                'blood_types.code as blood_type',
            ])
            ->get();
    }

    public function monthlyCompletedDonationCounts(int $donorId, Carbon $from, Carbon $to): Collection
    {
        return DB::table('donations')
            ->where('donor_id', $donorId)
            ->where('status', 'completed')
            ->whereBetween('donation_date', [$from, $to])
            ->groupBy('month_key')
            ->orderBy('month_key')
            ->selectRaw("DATE_FORMAT(donation_date, '%Y-%m') as month_key, COUNT(*) as total")
            ->get();
    }
}
