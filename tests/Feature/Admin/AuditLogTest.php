<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The admin audit log page's endpoint (2026-10-15): newest first, filterable,
 * and only for admins holding admin.audit.view.
 */
class AuditLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        return User::factory()->withRole(RoleName::Admin)->create();
    }

    private function log(?User $actor, string $action, string $at): AuditLog
    {
        $log = AuditLog::create(['actor_id' => $actor?->id, 'action' => $action, 'context' => ['facility_id' => 1]]);
        $log->forceFill(['created_at' => $at])->save();

        return $log;
    }

    public function test_entries_come_newest_first_with_the_actor_named(): void
    {
        $actor = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $this->log($actor, 'facility.created', '2026-10-01 09:00:00');
        $this->log($actor, 'facility.updated', '2026-10-02 09:00:00');

        $this->actingAs($this->admin())
            ->getJson('/api/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'facility.updated')
            ->assertJsonPath('data.0.category', 'facility')
            ->assertJsonPath('data.0.actor.full_name', 'Ana Cruz')
            ->assertJsonPath('data.1.action', 'facility.created');
    }

    public function test_entries_filter_by_category_action_actor_and_date(): void
    {
        $ana = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $ben = User::factory()->create(['first_name' => 'Ben', 'last_name' => 'Ong']);
        $this->log($ana, 'facility.created', '2026-10-01 09:00:00');
        $this->log($ben, 'billing.raised', '2026-10-05 09:00:00');
        $this->log($ana, 'billing.payment_recorded', '2026-10-09 09:00:00');

        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/admin/audit-logs?category=billing')->assertJsonCount(2, 'data');
        $this->actingAs($admin)->getJson('/api/admin/audit-logs?action=billing.raised')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/admin/audit-logs?search=Ana')->assertJsonCount(2, 'data');
        $this->actingAs($admin)->getJson('/api/admin/audit-logs?from=2026-10-04&to=2026-10-06')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'billing.raised');
    }

    public function test_the_categories_that_occur_are_listed_for_the_filter(): void
    {
        $this->log(null, 'facility.created', '2026-10-01 09:00:00');
        $this->log(null, 'billing.raised', '2026-10-02 09:00:00');

        $this->actingAs($this->admin())
            ->getJson('/api/admin/audit-logs')
            ->assertJsonPath('categories', ['billing', 'facility']);
    }

    public function test_non_admins_cannot_read_the_trail(): void
    {
        $this->getJson('/api/admin/audit-logs')->assertUnauthorized();

        $this->actingAs(User::factory()->bloodCenterSupervisor()->create())
            ->getJson('/api/admin/audit-logs')
            ->assertForbidden();
    }
}
