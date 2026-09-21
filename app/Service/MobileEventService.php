<?php

namespace App\Service;

use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Repository\MobileEventRepository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;

/**
 * The blood centre's own view of its mobile drives.
 *
 * Donors read drives through BookingCatalogController, which is deliberately
 * read-only. Scheduling one is an act of the Collection department, so it lives
 * here behind drives.manage.
 */
class MobileEventService
{
    public function __construct(
        private readonly MobileEventRepository $mobileEventRepository
    ) {}

    /**
     * The drives screen: headline figures plus this facility's drives.
     *
     * @return array<string, mixed>
     */
    public function list(User $staff): array
    {
        $facility = $this->requireFacility($staff);

        $drives = $this->mobileEventRepository->forFacility($facility->id);
        $upcoming = $drives->filter(
            fn (MobileEvent $event): bool => ! $event->event_date->isBefore(Carbon::today())
        );

        return [
            'upcoming_drives_count' => $upcoming->count(),
            'total_registered' => (int) $upcoming->sum('registered'),
            'units_collected_month' => $this->mobileEventRepository->unitsCollectedInMonth(
                $facility->id,
                Carbon::today()
            ),
            'drives' => $drives->map(fn (MobileEvent $event): array => $this->format($event))->all(),
        ];
    }

    /**
     * Schedule a drive for the facility the caller acts for.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(User $staff, array $payload): array
    {
        $facility = $this->requireFacility($staff);

        $event = $this->mobileEventRepository->create([
            ...$payload,
            // Both come from the token, never from the payload.
            'facility_id' => $facility->id,
            'created_by' => $staff->id,
        ]);

        return $this->format($event);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(MobileEvent $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'facility_name' => $event->facility?->name,
            'location' => $event->location,
            'event_date' => $event->event_date->toDateString(),
            'start_time' => $event->start_time,
            'end_time' => $event->end_time,
            'capacity' => $event->max_capacity,
            'registered_count' => (int) ($event->registered ?? 0),
            'assigned_staff' => $event->assigned_staff,
            'announcement' => $event->announcement,
            'status' => $event->status(),
            // No endpoint lists the donors registered to one drive yet, so the
            // roster stays empty rather than the page inventing one.
            'donor_preview' => [],
        ];
    }

    /**
     * The facility the caller acts for, resolved from the token rather than input.
     */
    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility
            ?? throw $this->refuse(404, 'facility_missing', 'This account is not linked to a facility.');
    }

    /**
     * Build the project's refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
