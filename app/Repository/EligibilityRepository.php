<?php

namespace App\Repository;

use App\Enums\AppointmentStatus;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonorQrToken;
use App\Models\EligibilityQuestion;
use App\Models\EligibilityScreening;
use App\Models\EligibilityScreeningAnswer;
use App\Support\OperationalDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EligibilityRepository
{
    /**
     * Get the active questions making up a questionnaire version.
     *
     * @return Collection<int, EligibilityQuestion>
     */
    public function questionsForVersion(int $version): Collection
    {
        return EligibilityQuestion::forVersion($version)->get();
    }

    /**
     * Get the donor's most recent screening, whatever its outcome.
     */
    public function latestScreening(int $donorId): ?EligibilityScreening
    {
        return EligibilityScreening::where('donor_id', $donorId)
            ->latest('screened_at')
            ->latest('id')
            ->first();
    }

    /**
     * When the donor last answered the questionnaire, whatever the outcome.
     *
     * A single value rather than the row, because the appointment list needs it
     * once for every booking the donor holds and loading a screening per
     * appointment would be an N+1 for a page that routinely shows several.
     */
    public function latestScreenedAt(int $donorId): ?Carbon
    {
        $screenedAt = EligibilityScreening::where('donor_id', $donorId)->max('screened_at');

        return $screenedAt ? Carbon::parse($screenedAt) : null;
    }

    /**
     * Get the donor's most recent screening that is still eligible and unexpired.
     */
    public function currentValidScreening(int $donorId): ?EligibilityScreening
    {
        return EligibilityScreening::where('donor_id', $donorId)
            ->currentlyValid()
            ->latest('screened_at')
            ->latest('id')
            ->first();
    }

    /**
     * Persist a screening record.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createScreening(array $payload): EligibilityScreening
    {
        return EligibilityScreening::create($payload);
    }

    /**
     * Persist the encrypted questionnaire answers for a screening.
     *
     * @param  array<string, bool>  $answers
     */
    public function storeAnswers(EligibilityScreening $screening, array $answers): void
    {
        foreach ($answers as $code => $answer) {
            EligibilityScreeningAnswer::create([
                'screening_id' => $screening->id,
                'question_code' => $code,
                'answer' => $answer,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Get the date blood was last actually drawn from this donor.
     *
     * Keyed on the existence of a `blood_collections` row, not on
     * `status = completed`. The 56-day interval exists to protect the donor's
     * body from a second draw too soon, so what matters is whether a bag came
     * out of their arm — not whether the laboratory has since cleared it for
     * issue, which can be days later and may never happen at all.
     *
     * Filtering on `completed` got both ends of that wrong: a donor who gave
     * blood this morning read as never having donated, and a donation rejected
     * for a reactive result stopped counting even though the draw was real.
     * A donation rejected at screening has no collection row, so it correctly
     * does not count.
     */
    public function lastBloodDrawnAt(int $donorId): ?Carbon
    {
        $donationDate = Donation::where('donor_id', $donorId)
            ->whereHas('collection')
            ->max('donation_date');

        return $donationDate ? Carbon::parse($donationDate) : null;
    }

    /**
     * Get the donor's newest check-in token that is neither revoked nor expired.
     */
    public function usableQrToken(int $donorId): ?DonorQrToken
    {
        return DonorQrToken::where('donor_id', $donorId)
            ->usable()
            ->latest('issued_at')
            ->latest('id')
            ->first();
    }

    /**
     * Persist a check-in token record.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createQrToken(array $payload): DonorQrToken
    {
        return DonorQrToken::create($payload);
    }

    /**
     * Revoke every outstanding check-in token belonging to the donor.
     */
    public function revokeQrTokens(int $donorId): void
    {
        DonorQrToken::where('donor_id', $donorId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * The donor's next booking that still holds its slot, if any.
     *
     * What decides whether the appointment screening window applies. A donor
     * with no active booking answers the questionnaire whenever they like.
     */
    public function nextActiveAppointment(int $donorId): ?DonationAppointment
    {
        return DonationAppointment::query()
            ->where('donor_id', $donorId)
            ->whereIn('status', AppointmentStatus::activeValues())
            ->where('appointment_datetime', '>=', OperationalDay::today()->startOfDay())
            ->orderBy('appointment_datetime')
            ->first();
    }

    /**
     * Determine whether this donor's QR was scanned at this facility on a date.
     *
     * One of the ways a donor counts as having presented at a centre, which is
     * what the questionnaire read is gated on. Reads the indexed column on the
     * token rather than scanning audit_logs for a qr_verified entry.
     */
    public function qrVerifiedAtFacilityOn(int $donorId, int $facilityId, string $date): bool
    {
        return DonorQrToken::query()
            ->where('donor_id', $donorId)
            ->where('last_used_facility_id', $facilityId)
            ->whereDate('last_used_at', $date)
            ->exists();
    }

    /**
     * A screening with everything the blood centre's questionnaire view needs.
     */
    public function screeningWithAnswers(int $screeningId): ?EligibilityScreening
    {
        return EligibilityScreening::query()
            ->with(['answers', 'donorProfile.donor', 'donorProfile.bloodType'])
            ->whereKey($screeningId)
            ->first();
    }

    /**
     * The donor's most recent screening, loaded for the questionnaire view.
     */
    public function latestScreeningWithAnswers(int $donorId): ?EligibilityScreening
    {
        return EligibilityScreening::query()
            ->with(['answers', 'donorProfile.donor', 'donorProfile.bloodType'])
            ->where('donor_id', $donorId)
            ->latest('screened_at')
            ->latest('id')
            ->first();
    }

    /**
     * Every question of a version, keyed by code, regardless of is_active.
     *
     * Deliberately not scopeForVersion(), which filters on is_active. This is
     * the read that resolves a historical answer's wording, and a question
     * deactivated years after the fact must still render the screening it was
     * part of. Filtering here would turn an old questionnaire into a list of
     * bare codes.
     *
     * @return Collection<string, EligibilityQuestion>
     */
    public function questionTextMap(int $version): Collection
    {
        return EligibilityQuestion::query()
            ->where('version', $version)
            ->orderBy('section_number')
            ->orderBy('number')
            ->orderBy('code')
            ->get()
            ->keyBy('code');
    }
}
