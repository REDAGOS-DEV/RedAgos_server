<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\User;
use App\Service\AuditLogger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The Blood Center Admin's Audit Log (project owner, 2026-10-21).
 */
class AuditLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->admin = User::factory()->bloodCenterAdmin($this->facility)->create();
    }

    public function test_the_admin_reads_their_own_staff_and_the_system_and_nobody_else(): void
    {
        $chair = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create(['first_name' => 'Neha', 'last_name' => 'Sharma']);
        $elsewhere = User::factory()->bloodCenterStaff(Facility::factory()->approved()->create(), StaffRole::Phlebotomist)->create();
        $donor = User::factory()->donor()->create();

        $this->audit($chair, 'appointments.exported', $this->facility, ['rows' => 3]);
        $this->audit($elsewhere, 'appointments.exported', $elsewhere->facility, ['rows' => 9]);
        $this->audit(null, 'stock_threshold.alerted', $this->facility, ['facility_id' => $this->facility->id]);
        // Filed under this centre by its context, but a donor's own doing.
        $this->audit($donor, 'eligibility.screening.created', null, ['facility_id' => $this->facility->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/blood-center/audit-log')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.user.name', 'System')
            ->assertJsonPath('data.0.type', 'send')
            ->assertJsonPath('data.0.module', 'inventory')
            ->assertJsonPath('data.1.user.name', 'Neha Sharma')
            ->assertJsonPath('data.1.user.role', StaffRole::Phlebotomist->label())
            ->assertJsonPath('data.1.type_label', 'Export')
            ->assertJsonPath('data.1.module_label', 'Appointments')
            ->assertJsonPath('data.1.record', null)
            ->assertJsonPath('data.1.details', 'Exported appointments · 3 rows');

        $this->assertContains(['value' => 'staff', 'label' => 'Staff'], $response->json('modules'));
        $this->assertNotContains('hospital', array_column($response->json('modules'), 'value'));
        $this->assertContains(['value' => 'sign_in', 'label' => 'Sign-in'], $response->json('types'));
    }

    public function test_only_the_admin_may_read_it(): void
    {
        $supervisor = User::factory()->bloodCenterSupervisor($this->facility)->create();
        $head = User::factory()->bloodCenterStaff($this->facility, StaffRole::InventoryControlOfficer)->create();

        $this->actingAs($supervisor)->getJson('/api/blood-center/audit-log')->assertForbidden();
        $this->actingAs($head)->getJson('/api/blood-center/audit-log')->assertForbidden();
        $this->actingAs($this->admin)->getJson('/api/blood-center/audit-log')->assertOk();
    }

    public function test_it_filters_by_module_type_date_and_search(): void
    {
        $chair = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create(['first_name' => 'Arjun', 'last_name' => 'Mehta']);
        $desk = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create(['first_name' => 'Liza', 'last_name' => 'Cruz']);

        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
        $this->audit($chair, 'auth.signed_in', $chair);

        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(10, 0));
        $this->audit($desk, 'request.released', null, ['reference_number' => 'RQ-4-0007', 'units' => ['RA1-1-01']]);
        $this->audit($desk, 'donors.exported', $this->facility, ['rows' => 12]);
        $this->travelBack();

        $list = fn (array $query) => $this->actingAs($this->admin)->getJson('/api/blood-center/audit-log?'.http_build_query($query))->assertOk();

        $list(['module' => 'account'])->assertJsonPath('total', 1)->assertJsonPath('data.0.action', 'auth.signed_in');
        $list(['type' => 'export'])->assertJsonPath('total', 1)->assertJsonPath('data.0.action', 'donors.exported');
        $list(['from' => '2026-10-02', 'to' => '2026-10-31'])->assertJsonPath('total', 2);
        $list(['to' => '2026-10-01'])->assertJsonPath('total', 1);
        $list(['search' => 'mehta'])->assertJsonPath('total', 1);
        $list(['search' => 'login'])->assertJsonPath('total', 1)->assertJsonPath('data.0.action', 'auth.signed_in');
        $list(['search' => 'RQ-4-0007'])->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.record', 'RQ-4-0007')
            ->assertJsonPath('data.0.details', 'Released a request · 1 unit');
    }

    public function test_a_donor_is_shown_by_code_and_never_by_name(): void
    {
        $physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $donor = User::factory()->donor()->create(['first_name' => 'Juan', 'last_name' => 'Delacruz']);

        $this->audit($physician, 'donor.viewed', $donor, ['facility_id' => $this->facility->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/blood-center/audit-log')
            ->assertOk()
            ->assertJsonPath('data.0.record', 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT))
            ->assertJsonPath('data.0.details', 'Opened a donor record');

        $this->assertStringNotContainsString('Delacruz', $response->getContent());
    }

    public function test_a_staff_change_reads_as_before_and_after(): void
    {
        $colleague = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'employee_id' => 'COL-007',
        ]);

        $this->audit($this->admin, 'staff.role_changed', $colleague, [
            'facility_id' => $this->facility->id,
            'from' => ['phlebotomist'],
            'to' => ['phlebotomist', 'medical_receptionist'],
        ]);

        $this->actingAs($this->admin)->getJson('/api/blood-center/audit-log?search=maria')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.record', 'COL-007')
            ->assertJsonPath('data.0.details', 'Changed staff roles · Maria Santos · '
                .StaffRole::Phlebotomist->label().' → '
                .StaffRole::Phlebotomist->label().', '.StaffRole::MedicalReceptionist->label());
    }

    public function test_a_refused_sign_in_names_the_account_it_was_tried_on(): void
    {
        User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create([
            'first_name' => 'Arjun',
            'last_name' => 'Mehta',
            'email' => 'arjun@example.com',
        ]);

        $this->postJson('/api/login', ['email' => 'arjun@example.com', 'password' => 'not-it'])->assertUnprocessable();

        $this->actingAs($this->admin)->getJson('/api/blood-center/audit-log')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.type', 'failed_sign_in')
            ->assertJsonPath('data.0.user.name', 'Unknown')
            ->assertJsonPath('data.0.details', 'Sign-in refused · Arjun Mehta · wrong password');
    }

    public function test_an_account_with_no_role_refused_on_the_single_page_says_why(): void
    {
        User::factory()->create([
            'facility_id' => $this->facility->id,
            'first_name' => 'Lito',
            'last_name' => 'Cruz',
            'email' => 'lito@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', ['email' => 'lito@example.com', 'password' => 'Password123'])->assertForbidden();

        $this->actingAs($this->admin)->getJson('/api/blood-center/audit-log')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.details', 'Sign-in refused · Lito Cruz · no portal access');
    }

    public function test_an_unknown_filter_is_refused(): void
    {
        $this->actingAs($this->admin)->getJson('/api/blood-center/audit-log?module=everything')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(?User $actor, string $action, mixed $subject = null, array $context = []): void
    {
        app(AuditLogger::class)->record($actor, $action, $subject, $context);
    }
}
