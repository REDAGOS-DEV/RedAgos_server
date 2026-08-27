<?php

namespace App\Service;

use App\Enums\DonationStatus;
use App\Enums\TestResult;
use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\Facility;
use App\Models\User;
use App\Repository\LaboratoryRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Laboratory/Processing: what was found, what it yielded, and whether it may be issued.
 *
 * This department owns a donation from `collected` to `completed`. It is the
 * only place `completed` can be written, and `completed` is what blood-unit
 * intake gates on — so the rules here are the last thing between an untested
 * bag and a patient.
 *
 * RedAgos does not perform the assay. A qualified professional does; this
 * records what they reported. Nothing here computes or infers a result.
 */
class LaboratoryService
{
    public function __construct(
        private readonly LaboratoryRepository $laboratoryRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Page the donations awaiting processing at this facility.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function queue(User $staff, array $filters, int $perPage): LengthAwarePaginator
    {
        $facility = $this->requireFacility($staff);

        return $this->laboratoryRepository
            ->paginateQueue($facility->id, $filters, $perPage)
            ->through(fn (Donation $donation): array => $this->format($donation));
    }

    /**
     * Show one donation with everything the laboratory has recorded against it.
     *
     * @return array<string, mixed>
     */
    public function show(User $staff, int $donationId): array
    {
        $facility = $this->requireFacility($staff);

        return $this->format($this->findOrFail($donationId, $facility));
    }

    /**
     * Record the screening outcome a qualified professional reported.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recordResult(User $staff, int $donationId, array $payload): array
    {
        $facility = $this->requireFacility($staff);
        $result = TestResult::from($payload['result']);

        $donation = DB::transaction(function () use ($staff, $facility, $donationId, $payload, $result): Donation {
            $locked = $this->lockOrFail($donationId, $facility);

            // Results belong to a donation the counter has finished with. A
            // donation still being screened has no bag to test.
            if (! in_array($locked->status, [DonationStatus::Collected, DonationStatus::Tested], true)) {
                throw $this->refuse(
                    409,
                    'donation_not_collected',
                    "A donation that is {$locked->status->label()} is not ready for processing."
                );
            }

            $this->guardBloodTypeMatchesDonor($locked, (int) $payload['blood_type_id']);

            $this->laboratoryRepository->upsertTestResult($locked->id, [
                'recorded_by' => $staff->id,
                'blood_type_id' => $payload['blood_type_id'],
                'result' => $result,
                'tested_at' => $payload['tested_at'] ?? now(),
                'notes' => isset($payload['notes']) ? trim((string) $payload['notes']) : null,
            ]);

            // Recording a result is what moves a donation to `tested`. It stays
            // there — clearing it for issue is a separate, deliberate act.
            $locked->status = DonationStatus::Tested;
            $locked->save();

            return $locked;
        });

        $this->auditLogger->record($staff, 'laboratory.result_recorded', $donation, [
            'facility_id' => $facility->id,
            'result' => $result->value,
        ]);

        return [
            'message' => 'Screening result recorded.',
            'data' => $this->format($this->findOrFail($donationId, $facility)),
        ];
    }

    /**
     * Declare which components the donation was separated into.
     *
     * This is the declaration blood-unit intake is constrained to. Inventory
     * may record up to `quantity` units per component and no more, so a bag
     * cannot be booked in for a component the laboratory never produced.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function declareComponents(User $staff, int $donationId, array $payload): array
    {
        $facility = $this->requireFacility($staff);

        $donation = DB::transaction(function () use ($staff, $facility, $donationId, $payload): Donation {
            $locked = $this->lockOrFail($donationId, $facility);

            if ($locked->status !== DonationStatus::Tested) {
                throw $this->refuse(
                    409,
                    'donation_not_tested',
                    'Record the screening result before declaring components.'
                );
            }

            // Redeclaring after units exist would let the declaration drift
            // below what inventory already recorded against it.
            if ($locked->bloodUnits()->exists()) {
                throw $this->refuse(
                    409,
                    'units_already_recorded',
                    'Inventory has already recorded units for this donation, so the component breakdown is fixed.'
                );
            }

            $this->laboratoryRepository->replaceComponents($locked->id, $payload['components'], $staff->id);

            return $locked;
        });

        $this->auditLogger->record($staff, 'laboratory.components_declared', $donation, [
            'facility_id' => $facility->id,
            'components' => count($payload['components']),
        ]);

        return [
            'message' => 'Component breakdown recorded.',
            'data' => $this->format($this->findOrFail($donationId, $facility)),
        ];
    }

    /**
     * Clear a donation for issue, or reject it.
     *
     * @return array<string, mixed>
     */
    public function updateStatus(User $staff, int $donationId, string $status): array
    {
        $facility = $this->requireFacility($staff);
        $target = DonationStatus::from($status);

        $donation = DB::transaction(function () use ($facility, $donationId, $target): Donation {
            $locked = $this->lockOrFail($donationId, $facility);

            match ($target) {
                DonationStatus::Completed => $this->guardReadyToComplete($locked),
                DonationStatus::Rejected => $this->guardRejectable($locked),
                default => throw $this->refuse(
                    409,
                    'invalid_transition',
                    'The laboratory may only complete or reject a donation.'
                ),
            };

            $locked->status = $target;
            $locked->save();

            return $locked;
        });

        $this->auditLogger->record($staff, 'laboratory.donation_'.$target->value, $donation, [
            'facility_id' => $facility->id,
        ]);

        return [
            'message' => $target === DonationStatus::Completed
                ? 'Donation cleared for issue. Inventory may now record its units.'
                : 'Donation rejected.',
            'data' => $this->format($this->findOrFail($donationId, $facility)),
        ];
    }

    /**
     * Refuse to clear a donation that is not genuinely ready.
     *
     * The three conditions are the whole point of this department. `completed`
     * is what blood-unit intake gates on, so anything that reaches it without
     * a passing result is blood going to a patient untested.
     */
    private function guardReadyToComplete(Donation $donation): void
    {
        if ($donation->status !== DonationStatus::Tested) {
            throw $this->refuse(
                409,
                'donation_not_tested',
                'Record a screening result before clearing a donation for issue.'
            );
        }

        $result = $donation->testResult()->first();

        if ($result === null) {
            throw $this->refuse(
                409,
                'result_missing',
                'Record a screening result before clearing a donation for issue.'
            );
        }

        if (! $result->result->clearsForIssue()) {
            throw $this->refuse(
                422,
                'result_not_passed',
                "A {$result->result->label()} donation cannot be cleared for issue. Reject it instead."
            );
        }

        if (! $this->laboratoryRepository->hasComponents($donation->id)) {
            throw $this->refuse(
                409,
                'components_missing',
                'Declare the component breakdown before clearing a donation for issue.'
            );
        }
    }

    /**
     * Refuse to reject a donation that has already been cleared.
     */
    private function guardRejectable(Donation $donation): void
    {
        if ($donation->status->isTerminal()) {
            throw $this->refuse(
                409,
                'donation_already_final',
                "This donation is already {$donation->status->label()}."
            );
        }
    }

    /**
     * Refuse a typed blood type that contradicts the donor's own record.
     *
     * A person's blood type does not change, so a mismatch means one of the two
     * records is wrong — and blood_units derives its type from the donor
     * profile. Letting the donation proceed would put a unit into stock labelled
     * with a type the laboratory did not read off the bag. Correcting the donor
     * profile is a Donor/Collection action, so this refuses rather than silently
     * picking a winner.
     */
    private function guardBloodTypeMatchesDonor(Donation $donation, int $typedBloodTypeId): void
    {
        $donorBloodTypeId = $donation->donorProfile?->blood_type_id;

        if ($donorBloodTypeId === null || (int) $donorBloodTypeId === $typedBloodTypeId) {
            return;
        }

        throw $this->refuse(
            409,
            'blood_type_mismatch',
            'The typed blood type does not match the donor record. Have Donor/Collection correct the donor profile before recording this result.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Donation $donation): array
    {
        $result = $donation->relationLoaded('testResult') ? $donation->testResult : $donation->testResult()->first();

        return [
            'id' => $donation->id,
            'donation_date' => $donation->donation_date?->toISOString(),
            'status' => $donation->status?->value,
            'status_label' => $donation->status?->label(),
            'owning_department' => $donation->status?->owningDepartment()?->value,
            'volume_ml' => $donation->volume_ml,
            'donor' => $donation->donorProfile?->donor ? [
                'uuid' => $donation->donorProfile->donor->uuid,
                'donor_code' => 'DONOR-'.str_pad((string) $donation->donorProfile->donor->id, 6, '0', STR_PAD_LEFT),
                'full_name' => trim($donation->donorProfile->donor->first_name.' '.$donation->donorProfile->donor->last_name),
                'blood_type' => $donation->donorProfile->bloodType?->code,
            ] : null,
            'test_result' => $result === null ? null : [
                'result' => $result->result?->value,
                'result_label' => $result->result?->label(),
                'clears_for_issue' => $result->result?->clearsForIssue(),
                'blood_type' => $result->bloodType?->code,
                'tested_at' => $result->tested_at?->toISOString(),
                'notes' => $result->notes,
            ],
            'components' => $this->laboratoryRepository
                ->componentsFor($donation->id)
                ->map(fn (DonationComponent $c): array => [
                    'component_id' => $c->component_id,
                    'component' => $c->component?->name,
                    'quantity' => $c->quantity,
                ])->all(),
        ];
    }

    /**
     * Re-read one of this facility's donations under a lock, or 404.
     */
    private function lockOrFail(int $donationId, Facility $facility): Donation
    {
        return $this->laboratoryRepository->lockDonation($donationId, $facility->id)
            ?? throw $this->refuse(404, 'donation_not_found', 'That donation was not found at your facility.');
    }

    /**
     * Find one of this facility's donations, or 404.
     */
    private function findOrFail(int $donationId, Facility $facility): Donation
    {
        return $this->laboratoryRepository->findDonation($donationId, $facility->id)
            ?? throw $this->refuse(404, 'donation_not_found', 'That donation was not found at your facility.');
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
