<?php

namespace App\Repository;

use App\Enums\DonationStatus;
use App\Models\BloodCollection;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonorQrToken;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The counter workflow: who is expected today, who has arrived, and what was drawn.
 *
 * Every query is scoped to one facility. A donor is global, but an appointment,
 * a donation and a collection all belong to the centre they happened at.
 */
class CollectionRepository
{
    /**
     * List the appointments booked at this facility for one day.
     *
     * @return Collection<int, DonationAppointment>
     */
    public function appointmentsForDay(int $facilityId, string $date, ?string $status = null): Collection
    {
        return DonationAppointment::query()
            ->with(['donorProfile.donor', 'donorProfile.bloodType'])
            ->where('facility_id', $facilityId)
            ->whereDate('appointment_datetime', $date)
            ->when($status !== null, fn (Builder $q): Builder => $q->where('status', $status))
            ->orderBy('appointment_datetime')
            ->orderBy('id')
            ->get();
    }

    /**
     * Re-read one of this facility's appointments under a row lock.
     *
     * Check-in and no-show both move the same row, and two staff members at two
     * counters is the ordinary case rather than the exotic one.
     */
    public function lockAppointment(int $appointmentId, int $facilityId): ?DonationAppointment
    {
        return DonationAppointment::query()
            ->where('id', $appointmentId)
            ->where('facility_id', $facilityId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Find a live QR token by the hash of the scanned value.
     *
     * The raw token is never stored, so the scanned string is hashed and
     * compared. Revoked and expired tokens are excluded here rather than
     * checked by the caller, so there is one definition of "usable".
     */
    public function findUsableQrToken(string $tokenHash): ?DonorQrToken
    {
        return DonorQrToken::query()
            ->with(['donorProfile.donor', 'donorProfile.bloodType'])
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Record that a QR token was presented at a counter.
     *
     * The token stays usable afterwards: a donor whose check-in is interrupted
     * should not be locked out of their own appointment. `last_used_at` is an
     * audit fact, not a consumption flag.
     */
    public function stampQrTokenUse(DonorQrToken $token): void
    {
        $token->last_used_at = now();
        $token->save();
    }

    /**
     * The donor's appointment at this facility around a given moment, if any.
     *
     * Used to attach a walk-in check-in to the booking it belongs to. Scoped to
     * the day so an appointment three weeks out is not silently consumed.
     */
    public function todaysAppointmentFor(int $donorId, int $facilityId, string $date): ?DonationAppointment
    {
        return DonationAppointment::query()
            ->where('donor_id', $donorId)
            ->where('facility_id', $facilityId)
            ->whereDate('appointment_datetime', $date)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->orderBy('appointment_datetime')
            ->first();
    }

    /**
     * Create a donation record for a donor who has presented at this facility.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createDonation(array $attributes): Donation
    {
        return Donation::create($attributes);
    }

    /**
     * Re-read one of this facility's donations under a row lock.
     */
    public function lockDonation(int $donationId, int $facilityId): ?Donation
    {
        return Donation::query()
            ->where('id', $donationId)
            ->where('facility_id', $facilityId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Find one of this facility's donations, without locking.
     */
    public function findDonation(int $donationId, int $facilityId): ?Donation
    {
        return Donation::query()
            ->with(['donorProfile.donor', 'donorProfile.bloodType', 'appointment'])
            ->where('id', $donationId)
            ->where('facility_id', $facilityId)
            ->first();
    }

    /**
     * Page this facility's donation queue.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Donation>
     */
    public function paginateDonations(int $facilityId, array $filters, int $perPage)
    {
        return Donation::query()
            ->with(['donorProfile.donor', 'donorProfile.bloodType'])
            ->where('facility_id', $facilityId)
            ->when(
                isset($filters['status']),
                fn (Builder $q): Builder => $q->where('status', $filters['status'])
            )
            ->when(
                isset($filters['date']),
                fn (Builder $q): Builder => $q->whereDate('donation_date', $filters['date'])
            )
            ->when(
                $filters['open_only'] ?? false,
                fn (Builder $q): Builder => $q->whereNotIn('status', [
                    DonationStatus::Completed->value,
                    DonationStatus::Rejected->value,
                ])
            )
            ->orderByDesc('donation_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Record the physical collection against a donation.
     *
     * `blood_collections.donation_id` is unique, so this is the one row that
     * names who drew the bag.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createCollection(array $attributes): BloodCollection
    {
        return BloodCollection::create($attributes);
    }

    /**
     * Determine whether a collection has already been recorded for a donation.
     */
    public function collectionExists(int $donationId): bool
    {
        return BloodCollection::query()->where('donation_id', $donationId)->exists();
    }
}
