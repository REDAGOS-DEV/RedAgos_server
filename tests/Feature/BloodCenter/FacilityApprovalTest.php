<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\FacilityStatus;
use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\FacilityRegistrationDecision;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacilityApprovalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        return User::factory()->withRole(RoleName::Admin)->create();
    }

    public function test_an_admin_can_approve_a_registration(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        $applicant = User::factory()->bloodCenterApplicant($facility)->create();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/api/admin/facility-registrations/{$facility->id}/approve")
            ->assertOk()
            ->assertJsonPath('facility.status', FacilityStatus::Approved->value);

        $facility->refresh();

        $this->assertSame(FacilityStatus::Approved, $facility->status);
        $this->assertNotNull($facility->approved_at);
        $this->assertSame($admin->id, $facility->approved_by);
        $this->assertTrue($applicant->fresh()->hasRole(RoleName::BloodCenter));
    }

    public function test_approval_grants_the_role_to_every_account_at_the_facility(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        $contact = User::factory()->bloodCenterApplicant($facility)->create();
        $colleague = User::factory()->create(['facility_id' => $facility->id]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facility-registrations/{$facility->id}/approve")
            ->assertOk();

        // A centre with several staff is approved once, not once per person.
        $this->assertTrue($contact->fresh()->hasRole(RoleName::BloodCenter));
        $this->assertTrue($colleague->fresh()->hasRole(RoleName::BloodCenter));
    }

    public function test_approving_twice_is_refused(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/api/admin/facility-registrations/{$facility->id}/approve")
            ->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/admin/facility-registrations/{$facility->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'facility_not_pending');
    }

    public function test_an_admin_can_reject_a_registration(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        $applicant = User::factory()->bloodCenterApplicant($facility)->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facility-registrations/{$facility->id}/reject", [
                'reason' => 'The DOH licence number does not match our records.',
            ])
            ->assertOk()
            ->assertJsonPath('facility.status', FacilityStatus::Rejected->value);

        $facility->refresh();

        $this->assertSame(FacilityStatus::Rejected, $facility->status);
        $this->assertSame('The DOH licence number does not match our records.', $facility->rejection_reason);
        $this->assertFalse($applicant->fresh()->hasRole(RoleName::BloodCenter));
    }

    public function test_rejection_requires_a_reason(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facility-registrations/{$facility->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_rejecting_an_approved_facility_is_refused(): void
    {
        $facility = Facility::factory()->approved()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facility-registrations/{$facility->id}/reject", ['reason' => 'Too late.'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'facility_not_pending');
    }

    public function test_an_admin_can_suspend_an_approved_facility(): void
    {
        $facility = Facility::factory()->approved()->create();
        $staff = User::factory()->bloodCenterStaff($facility)->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facility-registrations/{$facility->id}/suspend", [
                'reason' => 'Under investigation.',
            ])
            ->assertOk()
            ->assertJsonPath('facility.status', FacilityStatus::Suspended->value);

        // The role stays attached on purpose: suspension is enforced by the
        // operational middleware reading status, not by tearing down roles.
        $this->assertTrue($staff->fresh()->hasRole(RoleName::BloodCenter));
    }

    public function test_suspending_a_pending_facility_is_refused(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facility-registrations/{$facility->id}/suspend", ['reason' => 'No.'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'facility_not_approved');
    }

    public function test_an_admin_can_reinstate_a_suspended_facility(): void
    {
        $facility = Facility::factory()->suspended()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facility-registrations/{$facility->id}/reinstate")
            ->assertOk()
            ->assertJsonPath('facility.status', FacilityStatus::Approved->value);

        $this->assertNull($facility->refresh()->rejection_reason);
    }

    public function test_reinstating_an_approved_facility_is_refused(): void
    {
        $facility = Facility::factory()->approved()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facility-registrations/{$facility->id}/reinstate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'facility_not_suspended');
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function decisionActions(): array
    {
        return [
            'approve' => ['approve', []],
            'reject' => ['reject', ['reason' => 'No.']],
            'suspend' => ['suspend', ['reason' => 'No.']],
            'reinstate' => ['reinstate', []],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('decisionActions')]
    public function test_an_admin_cannot_decide_on_their_own_facility(string $action, array $payload): void
    {
        $facility = Facility::factory()->pendingApproval()->create();

        // role_user is many-to-many, so one account can hold admin and still be
        // attached to a facility. The guard runs before the status check, which
        // is why suspend and reinstate get 403 rather than 409 here.
        $admin = User::factory()->withRole(RoleName::Admin)->create([
            'facility_id' => $facility->id,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/facility-registrations/{$facility->id}/{$action}", $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'self_approval_forbidden');

        $this->assertSame(FacilityStatus::PendingApproval, $facility->refresh()->status);
    }

    public function test_approval_notifies_the_facility_staff(): void
    {
        Notification::fake();

        $facility = Facility::factory()->pendingApproval()->create();
        $applicant = User::factory()->bloodCenterApplicant($facility)->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facility-registrations/{$facility->id}/approve")
            ->assertOk();

        Notification::assertSentTo($applicant, FacilityRegistrationDecision::class);
    }

    public function test_the_review_queue_lists_pending_registrations_by_default(): void
    {
        Facility::factory()->pendingApproval()->create(['name' => 'Pending Center']);
        Facility::factory()->approved()->create(['name' => 'Approved Center']);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/admin/facility-registrations')
            ->assertOk();

        $names = array_column($response->json('data'), 'name');

        $this->assertContains('Pending Center', $names);
        $this->assertNotContains('Approved Center', $names);
    }

    public function test_the_review_queue_can_filter_by_status(): void
    {
        Facility::factory()->pendingApproval()->create(['name' => 'Pending Center']);
        Facility::factory()->rejected()->create(['name' => 'Rejected Center']);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/admin/facility-registrations?status=rejected')
            ->assertOk();

        $names = array_column($response->json('data'), 'name');

        $this->assertContains('Rejected Center', $names);
        $this->assertNotContains('Pending Center', $names);
    }

    public function test_the_review_queue_rejects_an_unknown_status(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/facility-registrations?status=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_the_admin_endpoints_reject_a_donor(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        $donor = User::factory()->donor()->create();

        $this->actingAs($donor)->getJson('/api/admin/facility-registrations')->assertForbidden();
        $this->actingAs($donor)
            ->postJson("/api/admin/facility-registrations/{$facility->id}/approve")
            ->assertForbidden();
    }

    public function test_the_admin_endpoints_reject_blood_center_staff(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        $staff = User::factory()->bloodCenterStaff()->create();

        $this->actingAs($staff)
            ->postJson("/api/admin/facility-registrations/{$facility->id}/approve")
            ->assertForbidden();
    }

    public function test_the_admin_endpoints_reject_unauthenticated_callers(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();

        $this->getJson('/api/admin/facility-registrations')->assertUnauthorized();
        $this->postJson("/api/admin/facility-registrations/{$facility->id}/approve")->assertUnauthorized();
    }
}
