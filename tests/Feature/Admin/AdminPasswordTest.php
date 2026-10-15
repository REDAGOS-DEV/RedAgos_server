<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * An admin changing their own password (2026-10-15).
 */
class AdminPasswordTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        return User::factory()->withRole(RoleName::Admin)->create(['password' => 'OldPass123']);
    }

    public function test_an_admin_can_change_their_password_and_every_session_ends(): void
    {
        $admin = $this->admin();
        $admin->createToken('admin-token');

        $this->actingAs($admin)
            ->postJson('/api/admin/account/password', [
                'current_password' => 'OldPass123',
                'password' => 'NewPass456',
                'password_confirmation' => 'NewPass456',
            ])
            ->assertOk();

        $admin->refresh();
        $this->assertTrue(Hash::check('NewPass456', $admin->password));
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertTrue(AuditLog::where('action', 'admin.password_changed')->where('actor_id', $admin->id)->exists());
    }

    public function test_the_current_password_must_be_right(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/admin/account/password', [
                'current_password' => 'Wrong123',
                'password' => 'NewPass456',
                'password_confirmation' => 'NewPass456',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('OldPass123', $admin->refresh()->password));
    }

    public function test_a_weak_or_unconfirmed_password_is_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/admin/account/password', [
                'current_password' => 'OldPass123',
                'password' => 'weak',
                'password_confirmation' => 'weak',
            ])
            ->assertJsonValidationErrors('password');

        $this->actingAs($admin)
            ->postJson('/api/admin/account/password', [
                'current_password' => 'OldPass123',
                'password' => 'NewPass456',
                'password_confirmation' => 'Different789',
            ])
            ->assertJsonValidationErrors('password');
    }

    public function test_only_admins_may_use_it(): void
    {
        $this->postJson('/api/admin/account/password', [])->assertUnauthorized();

        $this->actingAs(User::factory()->donor()->create())
            ->postJson('/api/admin/account/password', [])
            ->assertForbidden();
    }
}
