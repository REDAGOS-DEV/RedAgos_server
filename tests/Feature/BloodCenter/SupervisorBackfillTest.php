<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SupervisorBackfillTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Drive the migration's backfill directly.
     *
     * Requiring the migration file returns the anonymous class instance, so the
     * one-shot logic can be exercised against each facility shape without
     * rolling the schema back and rebuilding it.
     */
    private function runBackfill(): void
    {
        $migration = require database_path(
            'migrations/2026_08_26_065429_add_department_to_users_table.php'
        );

        $migration->backfillSupervisors();
    }

    /**
     * Build staff as the migration would find them: no supervisor marked yet.
     */
    private function legacyStaff(Facility $facility): User
    {
        $staff = User::factory()->bloodCenterStaff($facility)->create();

        $staff->is_supervisor = false;
        $staff->save();

        return $staff;
    }

    public function test_the_registration_contact_is_promoted(): void
    {
        $facility = Facility::factory()->approved()->create();
        $contact = $this->legacyStaff($facility);
        $colleague = $this->legacyStaff($facility);

        $facility->registration_contact_user_id = $contact->id;
        $facility->save();

        $this->runBackfill();

        $this->assertTrue($contact->fresh()->is_supervisor);
        $this->assertFalse($colleague->fresh()->is_supervisor, 'Only the contact should be promoted.');
    }

    public function test_a_facility_with_staff_but_no_contact_promotes_the_lowest_id(): void
    {
        $facility = Facility::factory()->approved()->create();
        $first = $this->legacyStaff($facility);
        $second = $this->legacyStaff($facility);

        $facility->registration_contact_user_id = null;
        $facility->save();

        $this->runBackfill();

        $this->assertTrue($first->fresh()->is_supervisor);
        $this->assertFalse($second->fresh()->is_supervisor);

        // The choice is written back so it is inspectable after the fact.
        $this->assertSame($first->id, $facility->fresh()->registration_contact_user_id);
    }

    public function test_a_stale_contact_id_falls_through_to_the_deterministic_fallback(): void
    {
        $facility = Facility::factory()->approved()->create();
        $staff = $this->legacyStaff($facility);

        // Points at somebody who does not work here — a contact who moved on.
        $outsider = User::factory()->bloodCenterStaff()->create();
        $facility->registration_contact_user_id = $outsider->id;
        $facility->save();

        $this->runBackfill();

        $this->assertTrue($staff->fresh()->is_supervisor, 'The facility must not be left without a supervisor.');
    }

    public function test_a_facility_with_no_staff_promotes_nobody_and_does_not_fail(): void
    {
        // The shape the seeded Davao centres have: approved, no contact, no users.
        $facility = Facility::factory()->approved()->create([
            'registration_contact_user_id' => null,
        ]);

        $this->runBackfill();

        $this->assertSame(0, User::where('facility_id', $facility->id)->count());
        $this->assertNull($facility->fresh()->registration_contact_user_id);
    }

    public function test_the_backfill_is_idempotent(): void
    {
        $facility = Facility::factory()->approved()->create();
        $contact = $this->legacyStaff($facility);
        $this->legacyStaff($facility);

        $facility->registration_contact_user_id = $contact->id;
        $facility->save();

        $this->runBackfill();
        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(
            1,
            User::where('facility_id', $facility->id)->where('is_supervisor', true)->count(),
            'Replaying the backfill must not promote a second supervisor.'
        );
    }

    public function test_an_existing_supervisor_is_left_alone(): void
    {
        $facility = Facility::factory()->approved()->create();
        $contact = $this->legacyStaff($facility);
        $alreadyPromoted = User::factory()->bloodCenterSupervisor($facility)->create();

        $facility->registration_contact_user_id = $contact->id;
        $facility->save();

        $this->runBackfill();

        $this->assertTrue($alreadyPromoted->fresh()->is_supervisor);
        $this->assertFalse(
            $contact->fresh()->is_supervisor,
            'A facility that already has a supervisor should be skipped entirely.'
        );
    }

    public function test_soft_deleted_staff_are_never_promoted(): void
    {
        $facility = Facility::factory()->approved()->create();
        $departed = $this->legacyStaff($facility);
        $current = $this->legacyStaff($facility);

        $facility->registration_contact_user_id = $departed->id;
        $facility->save();
        $departed->delete();

        $this->runBackfill();

        $this->assertFalse((bool) $departed->fresh()->is_supervisor);
        $this->assertTrue($current->fresh()->is_supervisor);
    }

    public function test_each_facility_is_backfilled_independently(): void
    {
        $first = Facility::factory()->approved()->create();
        $firstStaff = $this->legacyStaff($first);

        $second = Facility::factory()->approved()->create();
        $secondStaff = $this->legacyStaff($second);

        $this->runBackfill();

        $this->assertTrue($firstStaff->fresh()->is_supervisor);
        $this->assertTrue($secondStaff->fresh()->is_supervisor);
    }

    public function test_approving_a_facility_guarantees_it_has_a_supervisor(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        $applicant = User::factory()->bloodCenterApplicant($facility)->create();

        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)
            ->postJson("/api/admin/facility-registrations/{$facility->id}/approve")
            ->assertOk();

        $this->assertTrue($applicant->fresh()->is_supervisor);
        $this->assertNull($applicant->fresh()->department, 'Approval grants the management level, not a department.');
    }

    public function test_reinstating_a_facility_does_not_promote_a_second_supervisor(): void
    {
        $facility = Facility::factory()->suspended()->create();
        $supervisor = User::factory()->bloodCenterSupervisor($facility)->create();
        $colleague = User::factory()->bloodCenterStaff($facility)->create();

        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)
            ->postJson("/api/admin/facility-registrations/{$facility->id}/reinstate")
            ->assertOk();

        $this->assertTrue($supervisor->fresh()->is_supervisor);
        $this->assertFalse($colleague->fresh()->is_supervisor);
    }
}
