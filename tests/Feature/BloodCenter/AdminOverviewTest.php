<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AccountStatus;
use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\User;
use App\Service\AuditLogger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The Blood Center Admin's dashboard: role coverage, the team, the setup.
 */
class AdminOverviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $centre;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Facility::factory()->approved()->create();
        $this->admin = User::factory()->bloodCenterAdmin($this->centre)->create();
    }

    private function overview(): array
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/blood-center/admin-overview')
            ->assertOk()
            ->json();
    }

    public function test_a_post_counts_as_filled_only_by_someone_who_can_sign_in(): void
    {
        User::factory()->bloodCenterStaff($this->centre, StaffRole::LabSupervisor)->create();
        User::factory()->bloodCenterStaff($this->centre, StaffRole::ScreeningPhysician)->create(['account_status' => AccountStatus::Suspended]);
        // Another centre's head fills nothing here.
        User::factory()->bloodCenterStaff(Facility::factory()->approved()->create(), StaffRole::InventoryControlOfficer)->create();

        $coverage = collect($this->overview()['coverage'])->keyBy('role');

        $this->assertTrue($coverage['lab_supervisor']['filled']);
        $this->assertSame(1, $coverage['lab_supervisor']['count']);
        $this->assertFalse($coverage['screening_physician']['filled']);
        $this->assertFalse($coverage['inventory_control_officer']['filled']);
        $this->assertSame(5, $this->overview()['needs']['coverage_gaps']);
    }

    public function test_it_counts_the_team_and_who_waits_for_the_admin(): void
    {
        User::factory()->bloodCenterStaff($this->centre, StaffRole::Phlebotomist)->create();
        User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create(['account_status' => AccountStatus::PendingVerification]);
        User::factory()->bloodCenterStaff($this->centre)->create(['staff_role' => null, 'custom_role' => null, 'department' => null]);
        User::factory()->bloodCenterStaff($this->centre, StaffRole::DispatchCoordinator)->create(['account_status' => AccountStatus::Deactivated]);

        $data = $this->overview();

        $this->assertSame(1, $data['needs']['pending_activation']);
        $this->assertSame(1, $data['needs']['unassigned']);
        $this->assertCount(1, $data['unassigned_staff']);
        // The Admin, the phlebotomist, the clerk and the unassigned account; not the deactivated one.
        $this->assertSame(4, $data['team']['total']);
        $this->assertSame(1, $data['team']['deactivated']);
        $this->assertSame(1, collect($data['team']['by_department'])->firstWhere('department', 'collection')['count']);
    }

    public function test_it_reports_the_missing_logo_and_seal(): void
    {
        $this->assertSame(['logo' => false, 'seal' => false], $this->overview()['setup']);
        $this->assertSame(2, $this->overview()['needs']['setup_missing']);

        $this->centre->forceFill(['logo_path' => 'facility-logos/x.png'])->save();
        // A new request loads the account afresh; this one is reused.
        $this->admin = $this->admin->fresh();

        $this->assertSame(1, $this->overview()['needs']['setup_missing']);
    }

    public function test_it_lists_recent_staff_and_settings_changes_only(): void
    {
        $logger = app(AuditLogger::class);
        $clerk = User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create();
        $logger->record($this->admin, 'staff.created', $clerk, ['facility_id' => $this->centre->id]);
        $logger->record($this->admin, 'center.logo_updated', $this->centre, ['facility_id' => $this->centre->id]);

        $data = $this->overview();
        $labels = collect($data['activity'])->pluck('label')->all();

        $this->assertSame(['Updated the centre logo', 'Added a staff account'], $labels);
        $this->assertSame(2, $data['changes_this_week']);
    }

    public function test_only_who_manages_staff_may_read_it(): void
    {
        $this->actingAs(User::factory()->bloodCenterStaff($this->centre, StaffRole::LabSupervisor)->create())
            ->getJson('/api/blood-center/admin-overview')
            ->assertForbidden();
    }
}
