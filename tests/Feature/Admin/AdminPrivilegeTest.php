<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use App\Support\AdminPrivileges;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The admin routes used to be guarded by role:admin alone, which made every
 * admin account unrestricted. These assert the narrowing actually bites: an
 * admin scoped to the donor ID queue must be refused everywhere else, and must
 * not be able to widen itself.
 */
class AdminPrivilegeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_super_admin_holds_every_privilege(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->assertTrue($admin->is_super_admin);
        $this->assertSame(AdminPrivileges::all(), $admin->abilities());
    }

    public function test_a_scoped_admin_holds_only_what_was_granted(): void
    {
        $admin = User::factory()->scopedAdmin(['admin.donor_identity.verify'])->create();

        $this->assertSame(['admin.donor_identity.verify'], $admin->abilities());
    }

    /**
     * A privilege dropped from the catalogue must not survive in old rows as a
     * gate nobody defines any more.
     */
    public function test_unknown_stored_privileges_are_ignored(): void
    {
        $admin = User::factory()->scopedAdmin([
            'admin.donor.view',
            'admin.retired.capability',
        ])->create();

        $this->assertSame(['admin.donor.view'], $admin->abilities());
    }

    public function test_an_admin_with_no_privileges_fails_closed(): void
    {
        $admin = User::factory()->scopedAdmin([])->create();

        $this->assertSame([], $admin->abilities());

        $this->actingAs($admin)->getJson('/api/admin/facilities')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/admin/donor-identities')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/users')->assertForbidden();
    }

    public function test_a_verification_officer_reaches_only_the_id_queue(): void
    {
        $officer = User::factory()
            ->scopedAdmin(AdminPrivileges::preset('verification_officer'))
            ->create();

        $this->actingAs($officer)->getJson('/api/admin/donor-identities')->assertOk();

        $this->actingAs($officer)->getJson('/api/admin/facilities')->assertForbidden();
        $this->actingAs($officer)->postJson('/api/admin/facilities', [])->assertForbidden();
        $this->actingAs($officer)->getJson('/api/users')->assertForbidden();
        $this->actingAs($officer)->getJson('/api/admin/privileges')->assertForbidden();
    }

    /**
     * Reading the facility list and deciding on an application are separate
     * grants, so manage alone must not be enough to approve one.
     */
    public function test_facility_manage_does_not_confer_approval(): void
    {
        $admin = User::factory()->scopedAdmin(['admin.facility.manage'])->create();

        // A real facility, because SubstituteBindings resolves the route model
        // before the gate runs — against a missing id this would 404 on binding
        // and never reach the `can:` check being asserted.
        $facility = Facility::factory()->create();

        $this->actingAs($admin)->getJson('/api/admin/facilities')->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/admin/facilities/{$facility->id}/approve")
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson("/api/admin/facilities/{$facility->id}/reject", ['reason' => 'Missing license'])
            ->assertForbidden();
    }

    public function test_a_super_admin_reaches_everything(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)->getJson('/api/admin/facilities')->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/donor-identities')->assertOk();
        $this->actingAs($admin)->getJson('/api/users')->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/privileges')->assertOk();
    }

    public function test_the_privilege_catalogue_is_served_to_the_account_form(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)
            ->getJson('/api/admin/privileges')
            ->assertOk()
            ->assertJsonPath('privileges.0.key', 'admin.donor_identity.verify')
            ->assertJsonCount(count(AdminPrivileges::all()), 'privileges');
    }

    /**
     * The escalation guard. admin.accounts.manage is what lets an admin create
     * accounts; without this a scoped admin could mint an unrestricted one and
     * make every other guard on these routes decorative.
     */
    public function test_a_scoped_admin_cannot_create_an_unrestricted_admin(): void
    {
        $admin = User::factory()->scopedAdmin(['admin.accounts.manage'])->create();

        $this->actingAs($admin)
            ->postJson('/api/users', $this->accountPayload(['is_super_admin' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_super_admin');
    }

    public function test_a_super_admin_can_create_an_unrestricted_admin(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)
            ->postJson('/api/users', $this->accountPayload(['is_super_admin' => true]))
            ->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'new.admin@redagos.ph',
            'is_super_admin' => true,
        ]);
    }

    public function test_a_scoped_admin_can_create_another_scoped_admin(): void
    {
        $admin = User::factory()->scopedAdmin(['admin.accounts.manage'])->create();

        $this->actingAs($admin)
            ->postJson('/api/users', $this->accountPayload([
                'admin_privileges' => ['admin.donor_identity.verify'],
            ]))
            ->assertCreated();

        $created = User::where('email', 'new.admin@redagos.ph')->firstOrFail();

        $this->assertFalse($created->is_super_admin);
        $this->assertSame(['admin.donor_identity.verify'], $created->abilities());
    }

    public function test_an_unknown_privilege_is_rejected_at_the_boundary(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)
            ->postJson('/api/users', $this->accountPayload([
                'admin_privileges' => ['admin.invented.capability'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_privileges.0');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function accountPayload(array $overrides = []): array
    {
        return [
            'first_name' => 'New',
            'last_name' => 'Admin',
            'email' => 'new.admin@redagos.ph',
            'username' => 'new.admin',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'roles' => [RoleName::Admin->value],
            ...$overrides,
        ];
    }
}
