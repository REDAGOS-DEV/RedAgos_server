<?php

namespace App\Service;

use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\DonationStatus;
use App\Enums\FacilityStatus;
use App\Enums\FacilityTypeName;
use App\Enums\IdentityStatus;
use App\Enums\RoleName;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Network-wide figures for the Super Admin (2026-10-15): the reports page, its
 * CSV exports, and the network section of the admin dashboard.
 *
 * Counts only, grouped in SQL: no patient or donor detail leaves this service.
 * Stock is what could be issued today (available and in date) at blood
 * centers that are in service, the same rule hospitals see.
 */
class NetworkReportService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'stock' => $this->stock(),
            'donations' => $this->donations($from, $to),
            'requests' => $this->requests($from, $to),
            'donors' => $this->donors($from, $to),
            'facilities' => $this->facilities(),
        ];
    }

    /**
     * Rows for one CSV export.
     *
     * @return array{0: array<int, string>, 1: Collection<int, array<int, mixed>>}
     */
    public function exportRows(string $dataset, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return match ($dataset) {
            'stock' => [
                ['Blood center', 'Blood type', 'Available units', 'Expiring within 3 days'],
                $this->stockByCenterAndType()->map(fn (object $row): array => [
                    $row->facility_name, $row->blood_type, (int) $row->available, (int) $row->expiring,
                ]),
            ],
            'donations' => [
                ['Blood center', 'Donations', 'Completed', 'Rejected'],
                $this->donationsByCenter($from, $to)->map(fn (object $row): array => [
                    $row->facility_name, (int) $row->total, (int) $row->completed, (int) $row->rejected,
                ]),
            ],
            default => [
                ['Requesting facility', 'Requests', 'Fulfilled', 'Partly fulfilled', 'Open', 'Rejected or cancelled', 'Emergency'],
                $this->requestsByFacility($from, $to)->map(fn (object $row): array => [
                    $row->facility_name, (int) $row->total, (int) $row->fulfilled, (int) $row->partial,
                    (int) $row->open, (int) $row->closed, (int) $row->emergency,
                ]),
            ],
        };
    }

    // --- Stock ----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function stock(): array
    {
        $rows = $this->stockByCenterAndType();

        $byType = BloodType::query()->orderBy('id')->pluck('code')->map(fn (string $code): array => [
            'blood_type' => $code,
            'available' => (int) $rows->where('blood_type', $code)->sum('available'),
            'expiring' => (int) $rows->where('blood_type', $code)->sum('expiring'),
        ])->values();

        $byCenter = $rows->groupBy('facility_id')->map(fn (Collection $group): array => [
            'facility_id' => (int) $group->first()->facility_id,
            'name' => $group->first()->facility_name,
            'available' => (int) $group->sum('available'),
            'expiring' => (int) $group->sum('expiring'),
        ])->sortByDesc('available')->values();

        return [
            'total' => (int) $rows->sum('available'),
            'expiring' => (int) $rows->sum('expiring'),
            'by_type' => $byType,
            'by_center' => $byCenter,
            // Blood types with no issuable unit anywhere in the network.
            'out_of_stock' => $byType->where('available', 0)->pluck('blood_type')->values(),
        ];
    }

    /**
     * Issuable units per blood center and blood type, with those expiring soon.
     *
     * @return Collection<int, object>
     */
    private function stockByCenterAndType(): Collection
    {
        $today = OperationalDay::todayAsDate();
        $soon = OperationalDay::today()->addDays(3)->toDateString();

        return BloodUnit::query()
            ->join('facilities', 'facilities.id', '=', 'blood_units.facility_id')
            ->join('facility_types', 'facility_types.id', '=', 'facilities.facility_type_id')
            ->join('blood_types', 'blood_types.id', '=', 'blood_units.blood_type_id')
            ->where('facility_types.name', FacilityTypeName::BloodCenter->value)
            ->where('facilities.status', FacilityStatus::Approved->value)
            ->whereNull('facilities.deleted_at')
            ->where('blood_units.status', BloodUnitStatus::Available->value)
            ->whereDate('blood_units.expiry_date', '>=', $today)
            ->groupBy('facilities.id', 'facilities.name', 'blood_types.id', 'blood_types.code')
            ->orderBy('facilities.name')
            ->orderBy('blood_types.id')
            ->selectRaw(
                'facilities.id as facility_id, facilities.name as facility_name, blood_types.code as blood_type, '
                .'COUNT(*) as available, '
                .'SUM(CASE WHEN blood_units.expiry_date <= ? THEN 1 ELSE 0 END) as expiring',
                [$soon]
            )
            ->get();
    }

    // --- Donations ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function donations(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $inRange = Donation::query()->whereBetween('donation_date', [$from->startOfDay(), $to->endOfDay()]);

        // The six calendar months ending with the range's last month.
        $trend = collect(range(5, 0))->map(function (int $back) use ($to): array {
            $month = $to->startOfMonth()->subMonths($back);

            return [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M'),
                'count' => Donation::query()
                    ->whereBetween('donation_date', [$month, $month->endOfMonth()])
                    ->where('status', '!=', DonationStatus::Rejected->value)
                    ->count(),
            ];
        })->values();

        return [
            'total' => (clone $inRange)->count(),
            'completed' => (clone $inRange)->where('status', DonationStatus::Completed->value)->count(),
            'rejected' => (clone $inRange)->where('status', DonationStatus::Rejected->value)->count(),
            'trend' => $trend,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function donationsByCenter(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Donation::query()
            ->join('facilities', 'facilities.id', '=', 'donations.facility_id')
            ->whereBetween('donations.donation_date', [$from->startOfDay(), $to->endOfDay()])
            ->groupBy('facilities.id', 'facilities.name')
            ->orderBy('facilities.name')
            ->selectRaw(
                'facilities.name as facility_name, COUNT(*) as total, '
                .'SUM(CASE WHEN donations.status = ? THEN 1 ELSE 0 END) as completed, '
                .'SUM(CASE WHEN donations.status = ? THEN 1 ELSE 0 END) as rejected',
                [DonationStatus::Completed->value, DonationStatus::Rejected->value]
            )
            ->get();
    }

    // --- Requests -------------------------------------------------------------

    /**
     * @return array<string, int>
     */
    private function requests(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $inRange = fn (): Builder => BloodRequest::query()->whereBetween('request_date', [$from->startOfDay(), $to->endOfDay()]);

        return [
            'total' => $inRange()->count(),
            'fulfilled' => $inRange()->where('status', BloodRequestStatus::Fulfilled->value)->count(),
            'partial' => $inRange()->where('status', BloodRequestStatus::Partial->value)->count(),
            'open' => $inRange()->whereIn('status', [BloodRequestStatus::Pending->value, BloodRequestStatus::Processing->value])->count(),
            'closed' => $inRange()->whereIn('status', [BloodRequestStatus::Rejected->value, BloodRequestStatus::Cancelled->value])->count(),
            'emergency' => $inRange()->where('urgency_level', UrgencyLevel::Emergency->value)->count(),
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function requestsByFacility(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $status = fn (array $values): string => "SUM(CASE WHEN blood_requests.status IN ('".implode("','", $values)."') THEN 1 ELSE 0 END)";

        return BloodRequest::query()
            ->join('facilities', 'facilities.id', '=', 'blood_requests.facility_id')
            ->whereBetween('blood_requests.request_date', [$from->startOfDay(), $to->endOfDay()])
            ->groupBy('facilities.id', 'facilities.name')
            ->orderBy('facilities.name')
            ->selectRaw(
                'facilities.name as facility_name, COUNT(*) as total, '
                .$status([BloodRequestStatus::Fulfilled->value]).' as fulfilled, '
                .$status([BloodRequestStatus::Partial->value]).' as partial, '
                .$status([BloodRequestStatus::Pending->value, BloodRequestStatus::Processing->value]).' as open, '
                .$status([BloodRequestStatus::Rejected->value, BloodRequestStatus::Cancelled->value]).' as closed, '
                .'SUM(CASE WHEN blood_requests.urgency_level = ? THEN 1 ELSE 0 END) as emergency',
                [UrgencyLevel::Emergency->value]
            )
            ->get();
    }

    // --- Donors and facilities ------------------------------------------------

    /**
     * @return array<string, int>
     */
    private function donors(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $donors = fn (): Builder => User::query()->whereHas('roles', fn (Builder $q) => $q->where('name', RoleName::Donor->value));

        return [
            'total' => $donors()->count(),
            'new' => $donors()->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])->count(),
            'verified' => DonorProfile::query()->where('identity_status', IdentityStatus::Verified->value)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function facilities(): array
    {
        $count = fn (FacilityTypeName $type, FacilityStatus $status): int => Facility::query()
            ->whereHas('facilityType', fn (Builder $q) => $q->where('name', $type->value))
            ->where('status', $status->value)
            ->count();

        return [
            'blood_centers' => $count(FacilityTypeName::BloodCenter, FacilityStatus::Approved),
            'blood_banks' => $count(FacilityTypeName::BloodBank, FacilityStatus::Approved),
            'inactive' => Facility::query()->where('status', FacilityStatus::Inactive->value)->count(),
        ];
    }
}
