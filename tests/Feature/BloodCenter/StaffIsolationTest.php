<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * staff.manage says the caller may manage staff. It says nothing about whose.
 *
 * The ability is facility-blind, so the boundary lives in the query layer:
 * every lookup is constrained to the authenticated caller's facility_id. A uuid
 * from another centre must therefore resolve to nothing and return 404 — not
 * 403, which would confirm that the account exists.
 */
class StaffIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $foreignStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()
            ->bloodCenterAdmin(Facility::factory()->approved()->create())
            ->create();

        $this->foreignStaff = User::factory()
            ->bloodCenterStaff(Facility::factory()->approved()->create())
            ->create();
    }

    /**
     * Every single-record route, with the verb it answers to.
     *
     * @return array<string, array{string, string}>
     */
    public static function singleRecordRoutes(): array
    {
        return [
            'show' => ['getJson', ''],
            'update' => ['patchJson', ''],
            'delete' => ['deleteJson', ''],
            'restore' => ['postJson', '/restore'],
        ];
    }

    #[DataProvider('singleRecordRoutes')]
    public function test_a_foreign_staff_uuid_is_not_found(string $method, string $suffix): void
    {
        $this->actingAs($this->admin)
            ->{$method}("/api/blood-center/staff/{$this->foreignStaff->uuid}{$suffix}", [
                'staff_role' => StaffRole::BillingClerk->value,
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'staff_not_found');

        // Nothing about the other centre's account may have changed.
        $this->assertNotSame(Department::Billing, $this->foreignStaff->fresh()->department);
        $this->assertNotSoftDeleted('users', ['id' => $this->foreignStaff->id]);
    }

    public function test_a_foreign_soft_deleted_account_cannot_be_restored(): void
    {
        $this->foreignStaff->delete();

        $this->actingAs($this->admin)
            ->postJson("/api/blood-center/staff/{$this->foreignStaff->uuid}/restore")
            ->assertNotFound();

        $this->assertSoftDeleted('users', ['id' => $this->foreignStaff->id]);
    }

    public function test_a_foreign_uuid_is_indistinguishable_from_an_unknown_one(): void
    {
        $unknown = (string) Str::uuid();

        $foreign = $this->actingAs($this->admin)
            ->getJson("/api/blood-center/staff/{$this->foreignStaff->uuid}");

        $missing = $this->actingAs($this->admin)
            ->getJson("/api/blood-center/staff/{$unknown}");

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        $this->assertSame($missing->json(), $foreign->json());
    }

    /**
     * Every roster route, so a new one cannot be added without a gate.
     *
     * @return array<string, array{string, string}>
     */
    public static function rosterRoutes(): array
    {
        return [
            'index' => ['getJson', '/api/blood-center/staff'],
            'store' => ['postJson', '/api/blood-center/staff'],
            'show' => ['getJson', '/api/blood-center/staff/{uuid}'],
            'update' => ['patchJson', '/api/blood-center/staff/{uuid}'],
            'delete' => ['deleteJson', '/api/blood-center/staff/{uuid}'],
            'restore' => ['postJson', '/api/blood-center/staff/{uuid}/restore'],
        ];
    }

    #[DataProvider('rosterRoutes')]
    public function test_department_staff_cannot_reach_the_roster(string $method, string $uri): void
    {
        $colleague = User::factory()
            ->bloodCenterStaff($this->admin->facility, Department::Issuance)
            ->create();

        $this->actingAs($colleague)
            ->{$method}(str_replace('{uuid}', $colleague->uuid, $uri))
            ->assertForbidden();
    }

    #[DataProvider('rosterRoutes')]
    public function test_the_roster_rejects_unauthenticated_callers(string $method, string $uri): void
    {
        $this->{$method}(str_replace('{uuid}', $this->foreignStaff->uuid, $uri))
            ->assertUnauthorized();
    }

    public function test_a_working_admin_still_manages_staff(): void
    {
        // The department must not narrow the level: an Admin who is also a
        // billing clerk manages the roster exactly as one with no post does.
        $working = User::factory()
            ->bloodCenterAdmin($this->admin->facility, Department::Billing)
            ->create();

        $this->actingAs($working)
            ->getJson('/api/blood-center/staff')
            ->assertOk();
    }

    public function test_an_admin_whose_facility_is_unapproved_is_refused(): void
    {
        $facility = Facility::factory()->rejected()->create();
        $admin = User::factory()->bloodCenterAdmin($facility)->create();

        // facility.operational runs before the ability, so roster management
        // stops the moment the centre stops being operational.
        $this->actingAs($admin)
            ->getJson('/api/blood-center/staff')
            ->assertForbidden()
            ->assertJsonPath('code', 'facility_not_approved');
    }
}
