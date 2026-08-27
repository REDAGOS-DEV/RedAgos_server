<?php

namespace App\Service;

use App\Enums\FacilityStatus;
use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\FacilityRegistrationDecision;
use App\Repository\BloodCenterRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class FacilityApprovalService
{
    public function __construct(
        private readonly BloodCenterRepository $bloodCenterRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * List facility registrations in a given state.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(FacilityStatus $status, int $perPage): LengthAwarePaginator
    {
        return $this->bloodCenterRepository
            ->registrationsByStatus($status, $perPage)
            ->through(fn (Facility $facility): array => $this->format($facility));
    }

    /**
     * Approve a registration and grant the blood_center role.
     *
     * @return array<string, mixed>
     */
    public function approve(User $admin, Facility $facility): array
    {
        $this->guardNotSelfDecision($admin, $facility);

        $updated = DB::transaction(function () use ($admin, $facility): Facility {
            $locked = $this->lockOrFail($facility);

            $this->guardStatus(
                $locked,
                FacilityStatus::PendingApproval,
                'facility_not_pending',
                'This registration is not awaiting approval.'
            );

            $locked->status = FacilityStatus::Approved;
            $locked->approved_at = now();
            $locked->approved_by = $admin->id;
            $locked->rejection_reason = null;
            $locked->save();

            $this->grantRoleToStaff($locked);
            $this->ensureSupervisor($locked);

            return $locked;
        });

        $this->auditLogger->record($admin, 'facility.approved', $updated, [
            'facility_id' => $updated->id,
        ]);

        $this->notifyStaff($updated, FacilityStatus::Approved);

        return [
            'message' => $updated->name.' has been approved.',
            'facility' => $this->format($updated),
        ];
    }

    /**
     * Reject a registration, recording why so the applicant can correct it.
     *
     * @return array<string, mixed>
     */
    public function reject(User $admin, Facility $facility, string $reason): array
    {
        $this->guardNotSelfDecision($admin, $facility);

        $updated = DB::transaction(function () use ($facility, $reason): Facility {
            $locked = $this->lockOrFail($facility);

            $this->guardStatus(
                $locked,
                FacilityStatus::PendingApproval,
                'facility_not_pending',
                'This registration is not awaiting a decision.'
            );

            $locked->status = FacilityStatus::Rejected;
            $locked->rejection_reason = $reason;
            $locked->save();

            return $locked;
        });

        $this->auditLogger->record($admin, 'facility.rejected', $updated, [
            'facility_id' => $updated->id,
        ]);

        $this->notifyStaff($updated, FacilityStatus::Rejected, $reason);

        return [
            'message' => $updated->name.' was not approved.',
            'facility' => $this->format($updated),
        ];
    }

    /**
     * Suspend an approved facility.
     *
     * The blood_center role is deliberately left attached. Suspension is
     * enforced by the facility.operational middleware reading the status, so
     * lifting it is a single reversible field change rather than a role rebuild.
     *
     * @return array<string, mixed>
     */
    public function suspend(User $admin, Facility $facility, string $reason): array
    {
        $this->guardNotSelfDecision($admin, $facility);

        $updated = DB::transaction(function () use ($facility, $reason): Facility {
            $locked = $this->lockOrFail($facility);

            $this->guardStatus(
                $locked,
                FacilityStatus::Approved,
                'facility_not_approved',
                'Only an approved facility can be suspended.'
            );

            $locked->status = FacilityStatus::Suspended;
            $locked->rejection_reason = $reason;
            $locked->save();

            return $locked;
        });

        $this->auditLogger->record($admin, 'facility.suspended', $updated, [
            'facility_id' => $updated->id,
        ]);

        $this->notifyStaff($updated, FacilityStatus::Suspended, $reason);

        return [
            'message' => $updated->name.' has been suspended.',
            'facility' => $this->format($updated),
        ];
    }

    /**
     * Return a suspended facility to service.
     *
     * @return array<string, mixed>
     */
    public function reinstate(User $admin, Facility $facility): array
    {
        $this->guardNotSelfDecision($admin, $facility);

        $updated = DB::transaction(function () use ($facility): Facility {
            $locked = $this->lockOrFail($facility);

            $this->guardStatus(
                $locked,
                FacilityStatus::Suspended,
                'facility_not_suspended',
                'Only a suspended facility can be reinstated.'
            );

            $locked->status = FacilityStatus::Approved;
            $locked->rejection_reason = null;
            $locked->save();

            // Roles were never detached, so this is idempotent. It runs anyway
            // so reinstatement self-heals any account that ended up attached to
            // the facility without the role.
            $this->grantRoleToStaff($locked);
            $this->ensureSupervisor($locked);

            return $locked;
        });

        $this->auditLogger->record($admin, 'facility.reinstated', $updated, [
            'facility_id' => $updated->id,
        ]);

        $this->notifyStaff($updated, FacilityStatus::Approved);

        return [
            'message' => $updated->name.' has been reinstated.',
            'facility' => $this->format($updated),
        ];
    }

    /**
     * Refuse a decision made by someone who works at the facility in question.
     */
    private function guardNotSelfDecision(User $admin, Facility $facility): void
    {
        // role_user is many-to-many, so one account can hold both admin and
        // blood_center and be attached to a facility. Without this an
        // administrator could approve, reject, suspend or reinstate their own
        // organisation.
        if ($admin->facility_id !== null && $admin->facility_id === $facility->id) {
            throw new HttpResponseException(response()->json([
                'message' => 'You cannot decide on a registration for your own facility.',
                'code' => 'self_approval_forbidden',
            ], 403));
        }
    }

    /**
     * Re-read the facility under a row lock so concurrent decisions serialise.
     */
    private function lockOrFail(Facility $facility): Facility
    {
        $locked = $this->bloodCenterRepository->lockFacility($facility->id);

        if (! $locked) {
            throw new HttpResponseException(response()->json([
                'message' => 'This facility no longer exists.',
                'code' => 'facility_missing',
            ], 404));
        }

        return $locked;
    }

    /**
     * Refuse a transition that is not valid from the facility's current state.
     *
     * Checked against the locked row, so the loser of a race sees the winner's
     * result and gets a 409 rather than writing over it.
     */
    private function guardStatus(
        Facility $facility,
        FacilityStatus $expected,
        string $code,
        string $message
    ): void {
        if ($facility->status === $expected) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], 409));
    }

    /**
     * Grant the blood_center role to every account at the facility.
     *
     * A centre with several staff is approved once, not once per person.
     */
    private function grantRoleToStaff(Facility $facility): void
    {
        foreach ($this->bloodCenterRepository->staffForFacility($facility->id) as $staff) {
            $this->bloodCenterRepository->attachRole($staff, RoleName::BloodCenter->value);
        }
    }

    /**
     * Ensure the newly approved facility has someone who can manage its staff.
     *
     * Without this the first account through the door holds the blood_center
     * role but not staff.manage, so an approved centre would have nobody able
     * to create colleagues or assign them departments. Skipped when a
     * supervisor already exists, so reinstating a suspended facility does not
     * promote a second one.
     */
    private function ensureSupervisor(Facility $facility): void
    {
        $staff = $this->bloodCenterRepository->staffForFacility($facility->id);

        if ($staff->isEmpty() || $staff->contains(fn (User $member): bool => $member->is_supervisor)) {
            return;
        }

        $supervisor = $staff->firstWhere('id', $facility->registration_contact_user_id)
            ?? $staff->sortBy('id')->first();

        $supervisor->is_supervisor = true;
        $supervisor->save();
    }

    /**
     * Mail every account at the facility. Sent after the commit.
     */
    private function notifyStaff(Facility $facility, FacilityStatus $decision, ?string $reason = null): void
    {
        $staff = $this->bloodCenterRepository->staffForFacility($facility->id);

        if ($staff->isEmpty()) {
            return;
        }

        Notification::send($staff, new FacilityRegistrationDecision($facility, $decision, $reason));
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Facility $facility): array
    {
        return [
            'id' => $facility->id,
            'name' => $facility->name,
            'doh_license_number' => $facility->doh_license_number,
            'contact_person' => $facility->contact_person,
            'email' => $facility->email,
            'phone' => $facility->phone,
            'address' => $facility->address,
            'status' => $facility->status->value,
            'approved_at' => $facility->approved_at?->toIso8601String(),
            'approved_by' => $facility->approved_by,
            'rejection_reason' => $facility->rejection_reason,
            'resubmitted_at' => $facility->resubmitted_at?->toIso8601String(),
            'created_at' => $facility->created_at?->toIso8601String(),
        ];
    }
}
