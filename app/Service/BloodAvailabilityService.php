<?php

namespace App\Service;

use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\User;
use App\Repository\AvailabilityRepository;
use App\Support\OperationalDay;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * What stock the network holds, for a requester deciding where to ask.
 *
 * This service is read-only and must stay that way. The paper's workflow has a
 * requester browsing availability before they commit to anything, so a search
 * that reserved what it displayed would let idle browsing drain the network.
 * Every number here is advisory, and the response says so explicitly rather
 * than leaving the client to assume it.
 */
class BloodAvailabilityService
{
    public function __construct(
        private readonly AvailabilityRepository $availabilityRepository
    ) {}

    /**
     * Search the network for facilities holding a blood type and component.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function search(User $user, array $filters): array
    {
        $facilityId = $this->requireFacilityId($user);
        $today = OperationalDay::todayAsDate();

        $bloodType = BloodType::query()->findOrFail($filters['blood_type_id']);
        $component = BloodComponent::query()->findOrFail($filters['component_id']);
        $wanted = isset($filters['quantity']) ? (int) $filters['quantity'] : null;

        $counts = $this->availabilityRepository->countsByFacility(
            (int) $bloodType->id,
            (int) $component->id,
            $today,
            $facilityId
        );

        $holdings = $counts->map(fn (object $row): array => $this->formatHolding($row, $wanted))->all();

        return [
            'criteria' => [
                'blood_type' => ['id' => $bloodType->id, 'code' => $bloodType->code],
                'component' => ['id' => $component->id, 'name' => $component->name],
                'quantity' => $wanted,
            ],
            'facilities' => $holdings,
            'totals' => [
                'facilities_with_stock' => count($holdings),
                'units_available' => array_sum(array_column($holdings, 'available')),
            ],
            // Stated in the payload, not just in documentation. A client that
            // treats a search hit as a hold would double-book the network, and
            // this is the field that tells it not to.
            'advisory' => true,
            'as_of' => OperationalDay::today()->toIso8601String(),
        ];
    }

    /**
     * List every facility this caller may address a request to.
     *
     * @return array<string, mixed>
     */
    public function eligibleTargets(User $user): array
    {
        $facilityId = $this->requireFacilityId($user);

        $facilities = $this->availabilityRepository
            ->eligibleTargets($facilityId)
            ->map(fn (Facility $facility): array => [
                'id' => $facility->id,
                'name' => $facility->name,
                'address' => $facility->address,
                'contact_person' => $facility->contact_person,
                'email' => $facility->email,
                'phone' => $facility->phone,
                'operating_hours' => $facility->operating_hours,
            ])
            ->all();

        return [
            'facilities' => $facilities,
            'as_of' => OperationalDay::today()->toIso8601String(),
        ];
    }

    /**
     * Project one facility's holding, answering how much of the ask it covers.
     *
     * @return array<string, mixed>
     */
    private function formatHolding(object $row, ?int $wanted): array
    {
        $available = (int) $row->available;

        return [
            'facility' => [
                'id' => (int) $row->facility_id,
                'name' => $row->facility_name,
                'address' => $row->address,
            ],
            'available' => $available,
            // How much of the ask this facility could cover, which is not the
            // same as how much it holds. Naming it separately keeps the client
            // from having to redo the arithmetic and get it wrong.
            'can_fulfil' => $wanted === null ? $available : min($available, $wanted),
            'covers_request' => $wanted === null ? null : $available >= $wanted,
            'earliest_expiry' => $row->earliest_expiry,
        ];
    }

    /**
     * Resolve the caller's facility, refusing a staff account without one.
     *
     * Read from the authenticated user rather than request input, so a caller
     * cannot search or request as somebody else's facility.
     */
    private function requireFacilityId(User $user): int
    {
        return $user->facility_id ?? throw $this->refuse(
            404,
            'facility_missing',
            'This account is not linked to a facility.'
        );
    }

    /**
     * Build the project's standard refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
