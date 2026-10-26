<?php

namespace App\Repository;

use App\Enums\AppointmentStatus;
use App\Enums\ScreeningOutcome;
use App\Models\BloodCollection;
use App\Models\CorrectionRequest;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\MobileEvent;
use App\Models\PaperDonationSheet;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * A centre's paper sheets: the drives whose records were typed in afterwards,
 * and the checks a paper record is held to before it becomes a donation.
 */
class PaperDonationSheetRepository
{
    /**
     * This centre's sheets, the most recent drive first, with how many donors
     * each holds and how those visits ended.
     *
     * @return LengthAwarePaginator<int, PaperDonationSheet>
     */
    public function paginate(int $facilityId, int $perPage): LengthAwarePaginator
    {
        return $this->withCounts(PaperDonationSheet::query())
            ->with(['creator:id,first_name,last_name,credentials', 'mobileEvent:id,name,location'])
            ->where('facility_id', $facilityId)
            ->orderByDesc('held_on')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(int $sheetId, int $facilityId): ?PaperDonationSheet
    {
        return $this->withCounts(PaperDonationSheet::query())
            ->with(['creator:id,first_name,last_name,credentials', 'mobileEvent:id,name,location'])
            ->where('id', $sheetId)
            ->where('facility_id', $facilityId)
            ->first();
    }

    /**
     * Re-read a sheet under a row lock, so two people typing the same stack
     * cannot both add one donor.
     */
    public function lock(int $sheetId, int $facilityId): ?PaperDonationSheet
    {
        return PaperDonationSheet::query()
            ->where('id', $sheetId)
            ->where('facility_id', $facilityId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PaperDonationSheet
    {
        return PaperDonationSheet::create($attributes);
    }

    /**
     * The centre's drives a sheet may be opened for: those already held, and
     * today's. A drive still to come has no paper to type.
     *
     * @return Collection<int, MobileEvent>
     */
    public function drivesHeld(int $facilityId, int $limit = 100): Collection
    {
        return MobileEvent::query()
            ->where('facility_id', $facilityId)
            ->whereDate('event_date', '<=', OperationalDay::todayAsDate())
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'name', 'location', 'event_date', 'start_time']);
    }

    /**
     * Whether this donor is already on the sheet.
     */
    public function donorOnSheet(int $sheetId, int $donorId): bool
    {
        return Donation::query()
            ->where('paper_sheet_id', $sheetId)
            ->where('donor_id', $donorId)
            ->exists();
    }

    /**
     * A draw of this donor's, at any centre, closer than the donation interval
     * to the given day on either side.
     *
     * The interval counts in days, as the donor's next eligible date does
     * (EligibilityRuleEvaluator::nextEligibleDate): a draw within the
     * interval before the day would have made the donor ineligible on it, and
     * one within it after means the day's draw made that one too soon.
     */
    public function drawNear(int $donorId, string $date, int $intervalDays): ?Donation
    {
        // Date arithmetic on the operational date itself, then each end turned
        // into an instant the way the Donations list's own date filter does.
        $day = CarbonImmutable::parse($date);
        $first = $day->subDays($intervalDays - 1)->toDateString();
        $last = $day->addDays($intervalDays - 1)->toDateString();

        return Donation::query()
            ->where('donor_id', $donorId)
            ->whereHas('collection')
            ->where('donation_date', '>=', OperationalDay::boundsFor($first)[0])
            ->where('donation_date', '<=', OperationalDay::boundsFor($last)[1])
            ->orderBy('donation_date')
            ->first();
    }

    /**
     * The donor's booking for a drive that still holds its slot, under a lock.
     */
    public function lockDriveBooking(int $donorId, int $facilityId, int $mobileEventId): ?DonationAppointment
    {
        return DonationAppointment::query()
            ->where('donor_id', $donorId)
            ->where('facility_id', $facilityId)
            ->where('event_id', $mobileEventId)
            ->whereIn('status', AppointmentStatus::activeValues())
            ->orderBy('appointment_datetime')
            ->lockForUpdate()
            ->first();
    }

    /**
     * One of the sheet's donations, under a lock.
     */
    public function lockSheetDonation(int $sheetId, int $donationId, int $facilityId): ?Donation
    {
        return Donation::query()
            ->where('id', $donationId)
            ->where('paper_sheet_id', $sheetId)
            ->where('facility_id', $facilityId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Whether any department has acted on the donation since it was typed:
     * a test section, a result, components, a bag booked in, a clearance, a
     * referral, or a correction request. Until then the record is only what
     * was typed, and may be taken back.
     */
    public function touchedSinceEntry(Donation $donation): bool
    {
        return $donation->immunohematology()->exists()
            || $donation->serology()->exists()
            || $donation->testResult()->exists()
            || $donation->components()->exists()
            || $donation->bloodUnits()->exists()
            || $donation->clearances()->exists()
            || $donation->counsellingReferral()->exists()
            || CorrectionRequest::query()->where('donation_id', $donation->id)->exists();
    }

    /**
     * Rows typed from the sheet, drawn and deferred, for its summary line.
     */
    private function withCounts(Builder $query): Builder
    {
        return $query->withCount([
            'donations as rows_count',
            'donations as drawn_count' => fn (Builder $q) => $q->whereHas('collection'),
            'donations as deferred_count' => fn (Builder $q) => $q->whereHas(
                'screening',
                fn (Builder $s) => $s->where('outcome', '!=', ScreeningOutcome::Accepted->value)
            ),
        ]);
    }

    /**
     * Delete a paper record's rows: the collection, the screening, the donation.
     *
     * The screening's key restricts deletion, so it goes before the donation.
     */
    public function deleteDonation(Donation $donation): void
    {
        BloodCollection::query()->where('donation_id', $donation->id)->delete();
        $donation->screening()->delete();
        $donation->delete();
    }
}
