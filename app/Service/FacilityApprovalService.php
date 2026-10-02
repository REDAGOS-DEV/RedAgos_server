<?php

namespace App\Service;

use App\Enums\FacilityStatus;
use App\Enums\FacilityTypeName;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\FacilityRegistrationDecision;
use App\Repository\BloodCenterRepository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Decisions taken about a facility that already exists.
 *
 * approve and reject are legacy: nothing this application creates now enters
 * pending_approval, because a Super Admin creating a facility activates it in
 * the same transaction. They stay because the facilities that applied through
 * the removed public registration flow are preserved rather than deleted, and
 * somebody has to be able to clear them.
 */
class FacilityApprovalService
{
    public function __construct(
        private readonly BloodCenterRepository $bloodCenterRepository,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Approve a legacy pending registration and grant its staff their role.
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
     * Reject a legacy pending registration, recording why.
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
     * Refuse a decision made by someone who works at the facility in question.
     */
    private function guardNotSelfDecision(User $admin, Facility $facility): void
    {
        // role_user is many-to-many, so one account can hold both admin and
        // blood_center and be attached to a facility. Without this an
        // administrator could approve or reject a registration for their own
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
     * Grant every account at the facility the role its facility type carries.
     *
     * The role follows the type rather than being assumed, so a blood bank is
     * not handed blood-centre access by a code path that only ever expected
     * one kind of organisation. A facility with several staff is approved
     * once, not once per person.
     */
    private function grantRoleToStaff(Facility $facility): void
    {
        $role = $this->requireFacilityType($facility)->role();

        foreach ($this->bloodCenterRepository->staffForFacility($facility->id) as $staff) {
            $this->bloodCenterRepository->attachRole($staff, $role->value);
        }
    }

    /**
     * Resolve the facility type, refusing rather than guessing at an unknown one.
     *
     * facility_types is an open table. Granting a default role to a facility
     * filed under a type the application has never heard of would hand out
     * access nobody chose, so this fails closed instead.
     */
    private function requireFacilityType(Facility $facility): FacilityTypeName
    {
        $facility->loadMissing('facilityType');

        $type = FacilityTypeName::tryFrom((string) $facility->facilityType?->name);

        if ($type === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'This facility is filed under a type this system cannot grant access for.',
                'code' => 'facility_type_unsupported',
            ], 409));
        }

        return $type;
    }

    /**
     * Ensure the newly approved facility has someone who can manage its staff.
     *
     * Without this the first account through the door holds the facility role
     * but not staff.manage, so an approved centre would have nobody able
     * to create colleagues or assign them departments. Skipped when a
     * supervisor already exists, so a facility that already has one does not
     * gain a second.
     */
    private function ensureSupervisor(Facility $facility): void
    {
        $staff = $this->bloodCenterRepository->staffForFacility($facility->id);

        if ($staff->isEmpty() || $staff->contains(fn (User $member): bool => $member->is_supervisor)) {
            return;
        }

        // registration_contact_user_id keeps its historical name and holds the
        // facility's primary account, so it is who to promote when there is one.
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
