<?php

namespace Tests\Feature\Admin;

use App\Enums\FacilityStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use App\Repository\AppointmentRepository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * A Super Admin correcting a facility and taking it out of service
 * (project owner, 2026-10-15).
 *
 * Deactivating is the point of these tests: an inactive facility's staff must
 * not keep a working session, cannot sign back in, and donors cannot book it.
 */
class FacilityLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        return User::factory()->withRole(RoleName::Admin)->create();
    }

    // --- Edit ---------------------------------------------------------------

    public function test_a_super_admin_can_correct_a_facilitys_details(): void
    {
        $facility = Facility::factory()->approved()->create(['name' => 'Davao Blod Center']);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/facilities/{$facility->id}", [
                'name' => 'Davao Blood Center',
                'address' => 'Quirino Ave, Davao City',
                'phone' => '0917-765-4321',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Davao Blood Center')
            ->assertJsonPath('data.phone', '+639177654321');

        $this->assertSame('Quirino Ave, Davao City', $facility->refresh()->address);
    }

    public function test_an_edit_writes_an_audit_log_naming_the_fields_changed(): void
    {
        $admin = $this->admin();
        $facility = Facility::factory()->approved()->create();

        $this->actingAs($admin)
            ->patchJson("/api/admin/facilities/{$facility->id}", ['address' => 'New address'])
            ->assertOk();

        $log = AuditLog::where('action', 'facility.updated')->latest('id')->firstOrFail();

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(['address'], $log->context['fields']);
    }

    public function test_an_edit_may_keep_the_facilitys_own_license_but_not_take_anothers(): void
    {
        $other = Facility::factory()->approved()->create(['doh_license_number' => 'DOH-TAKEN-1']);
        $facility = Facility::factory()->approved()->create(['doh_license_number' => 'DOH-MINE-1']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson("/api/admin/facilities/{$facility->id}", ['doh_license_number' => 'DOH-MINE-1'])
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson("/api/admin/facilities/{$facility->id}", ['doh_license_number' => $other->doh_license_number])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doh_license_number');
    }

    public function test_an_edit_cannot_change_the_status(): void
    {
        $facility = Facility::factory()->approved()->create();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/facilities/{$facility->id}", ['status' => 'inactive'])
            ->assertOk();

        $this->assertSame(FacilityStatus::Approved, $facility->refresh()->status);
    }

    // --- Deactivate ---------------------------------------------------------

    public function test_deactivating_takes_the_facility_out_of_service_and_records_why(): void
    {
        $admin = $this->admin();
        $facility = Facility::factory()->approved()->create();

        $this->actingAs($admin)
            ->postJson("/api/admin/facilities/{$facility->id}/deactivate", ['reason' => 'License expired'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertSame(FacilityStatus::Inactive, $facility->refresh()->status);

        $log = AuditLog::where('action', 'facility.deactivated')->latest('id')->firstOrFail();
        $this->assertSame('License expired', $log->context['reason']);
    }

    public function test_deactivating_needs_a_reason(): void
    {
        $facility = Facility::factory()->approved()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facilities/{$facility->id}/deactivate", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_only_an_active_facility_can_be_deactivated(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facilities/{$facility->id}/deactivate", ['reason' => 'x'])
            ->assertUnprocessable();
    }

    public function test_deactivating_ends_every_session_its_staff_hold(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();
        $staff->createToken('blood_center-token');

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facilities/{$staff->facility_id}/deactivate", ['reason' => 'Closed'])
            ->assertOk();

        $this->assertSame(0, $staff->tokens()->count());
    }

    public function test_an_inactive_facilitys_staff_are_refused_with_a_reason(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();
        $staff->facility->forceFill(['status' => FacilityStatus::Inactive])->save();

        $this->actingAs($staff->refresh())
            ->getJson('/api/blood-center/reference-data')
            ->assertForbidden()
            ->assertJsonPath('code', 'facility_inactive');
    }

    public function test_an_inactive_facilitys_staff_cannot_sign_in(): void
    {
        $staff = User::factory()->bloodBankStaff()->create(['password' => bcrypt('SecurePass1')]);
        $staff->facility->forceFill(['status' => FacilityStatus::Inactive])->save();

        $this->postJson('/api/login', [
            'email' => $staff->email,
            'password' => 'SecurePass1',
            'role' => 'hospital',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'facility_inactive');
    }

    public function test_donors_cannot_book_an_inactive_blood_center(): void
    {
        $open = Facility::factory()->approved()->create(['name' => 'Open Center']);
        $closed = Facility::factory()->approved()->create(['name' => 'Closed Center']);
        $closed->forceFill(['status' => FacilityStatus::Inactive])->save();

        $repository = app(AppointmentRepository::class);

        // The same facility, before and after: only the status differs.
        $this->assertNotNull($repository->findBookableCenter($open->id));
        $this->assertNull($repository->findBookableCenter($closed->id));
        $this->assertNotContains('Closed Center', $repository->bookableCenters()->pluck('name'));
    }

    // --- Reactivate ---------------------------------------------------------

    public function test_reactivating_puts_the_facility_back_and_its_staff_can_sign_in(): void
    {
        $staff = User::factory()->bloodBankStaff()->create(['password' => bcrypt('SecurePass1')]);
        $staff->facility->forceFill(['status' => FacilityStatus::Inactive])->save();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facilities/{$staff->facility_id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->postJson('/api/login', [
            'email' => $staff->email,
            'password' => 'SecurePass1',
            'role' => 'hospital',
        ])->assertOk();

        $this->assertTrue(AuditLog::where('action', 'facility.reactivated')->exists());
    }

    public function test_only_a_deactivated_facility_can_be_reactivated(): void
    {
        $facility = Facility::factory()->approved()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facilities/{$facility->id}/reactivate")
            ->assertUnprocessable();
    }

    // --- Who may ------------------------------------------------------------

    public function test_non_admins_cannot_edit_deactivate_or_reactivate(): void
    {
        $facility = Facility::factory()->approved()->create();
        $staff = User::factory()->bloodCenterSupervisor()->create();

        $this->actingAs($staff)->patchJson("/api/admin/facilities/{$facility->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($staff)->postJson("/api/admin/facilities/{$facility->id}/deactivate", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($staff)->postJson("/api/admin/facilities/{$facility->id}/reactivate")->assertForbidden();

        $this->assertSame(FacilityStatus::Approved, $facility->refresh()->status);
    }

    public function test_an_unknown_facility_is_a_404(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson('/api/admin/facilities/999999', ['name' => 'X'])->assertNotFound();
        $this->actingAs($admin)->postJson('/api/admin/facilities/999999/deactivate', ['reason' => 'x'])->assertNotFound();
    }
}
