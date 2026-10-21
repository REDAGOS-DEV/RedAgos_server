<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Sign-ins, sign-outs and refused sign-ins are written to the audit trail for
 * the Blood Center Admin's Audit Log (project owner, 2026-10-21).
 */
class SignInAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_sign_in_is_recorded_against_the_account_and_its_facility(): void
    {
        $facility = Facility::factory()->approved()->create();
        $staff = User::factory()->bloodCenterStaff($facility, StaffRole::Phlebotomist)->create([
            'email' => 'chair@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', ['email' => 'chair@example.com', 'password' => 'Password123'])->assertOk();

        $row = AuditLog::query()->where('action', 'auth.signed_in')->sole();

        $this->assertSame($staff->id, $row->actor_id);
        $this->assertSame($facility->id, $row->facility_id);
        $this->assertSame(User::class, $row->auditable_type);
        $this->assertSame((string) $staff->id, $row->auditable_id);
    }

    public function test_a_wrong_password_is_recorded_with_no_actor_and_without_what_was_typed(): void
    {
        $facility = Facility::factory()->approved()->create();
        $staff = User::factory()->bloodCenterStaff($facility, StaffRole::Phlebotomist)->create([
            'email' => 'chair@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', ['email' => 'chair@example.com', 'password' => 'Guess-1'])->assertUnprocessable();

        $row = AuditLog::query()->where('action', 'auth.sign_in_failed')->sole();

        $this->assertNull($row->actor_id);
        $this->assertSame($facility->id, $row->facility_id);
        $this->assertSame((string) $staff->id, $row->auditable_id);
        $this->assertSame(['facility_id' => $facility->id, 'reason' => 'wrong_password'], $row->context);
        $this->assertStringNotContainsString('Guess-1', json_encode($row->context));
        $this->assertStringNotContainsString('chair@example.com', json_encode($row->context));
    }

    public function test_a_wrong_password_by_username_is_recorded_without_the_username(): void
    {
        $facility = Facility::factory()->approved()->create();
        $staff = User::factory()->bloodCenterStaff($facility, StaffRole::Phlebotomist)->create([
            'username' => 'chair-q7w2er',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', ['login' => 'chair-q7w2er', 'password' => 'Guess-1'])->assertUnprocessable();

        $row = AuditLog::query()->where('action', 'auth.sign_in_failed')->sole();

        $this->assertSame((string) $staff->id, $row->auditable_id);
        $this->assertSame(['facility_id' => $facility->id, 'reason' => 'wrong_password'], $row->context);
    }

    public function test_an_address_that_matches_no_account_records_nothing(): void
    {
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'Password123'])->assertUnprocessable();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_the_right_password_on_a_deactivated_account_is_recorded_as_refused(): void
    {
        $facility = Facility::factory()->approved()->create();
        User::factory()->bloodCenterStaff($facility, StaffRole::Phlebotomist)->deactivated()->create([
            'email' => 'former@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', ['email' => 'former@example.com', 'password' => 'Password123'])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_deactivated');

        $row = AuditLog::query()->where('action', 'auth.sign_in_failed')->sole();

        $this->assertSame('account_deactivated', $row->context['reason']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.signed_in']);
    }

    public function test_signing_in_from_the_wrong_portal_is_recorded_as_refused(): void
    {
        User::factory()->donor()->create([
            'email' => 'donor@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', ['email' => 'donor@example.com', 'password' => 'Password123', 'role' => 'blood_center'])
            ->assertForbidden();

        $this->assertSame('role_mismatch', AuditLog::query()->where('action', 'auth.sign_in_failed')->sole()->context['reason']);
    }

    public function test_the_single_sign_in_page_records_the_portal_the_account_belongs_to(): void
    {
        $staff = User::factory()->bloodCenterStaff(null, StaffRole::Phlebotomist)->create([
            'email' => 'chair@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', ['email' => 'chair@example.com', 'password' => 'Password123'])->assertOk();

        $this->assertSame('blood_center', AuditLog::query()->where('action', 'auth.signed_in')->sole()->context['portal']);
        $this->assertSame('blood_center-token', $staff->tokens()->sole()->name);
    }

    public function test_an_account_with_several_roles_records_no_portal(): void
    {
        $user = User::factory()->donor()->withRole(RoleName::BloodBank)->create([
            'email' => 'both@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', ['email' => 'both@example.com', 'password' => 'Password123'])->assertOk();

        $this->assertArrayNotHasKey('portal', AuditLog::query()->where('action', 'auth.signed_in')->sole()->context ?? []);
        $this->assertSame('api-token', $user->tokens()->sole()->name);
    }

    public function test_the_single_sign_in_page_records_why_it_refused(): void
    {
        User::factory()->withRole(RoleName::Admin)->create(['email' => 'admin@example.com', 'password' => 'Password123']);
        User::factory()->create(['email' => 'nobody@example.com', 'password' => 'Password123']);

        $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'Password123'])->assertForbidden();
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'Password123'])->assertForbidden();

        $this->assertEqualsCanonicalizing(
            ['admin_sign_in_required', 'no_portal_access'],
            AuditLog::query()->where('action', 'auth.sign_in_failed')->get()->pluck('context.reason')->all()
        );
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.signed_in']);
    }

    public function test_signing_out_is_recorded(): void
    {
        $facility = Facility::factory()->approved()->create();
        $staff = User::factory()->bloodCenterStaff($facility, StaffRole::Phlebotomist)->create();
        $token = $staff->createToken('api-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertNoContent();

        $row = AuditLog::query()->where('action', 'auth.signed_out')->sole();

        $this->assertSame($staff->id, $row->actor_id);
        $this->assertSame($facility->id, $row->facility_id);
    }

    public function test_signing_out_everywhere_says_so(): void
    {
        $staff = User::factory()->bloodCenterStaff(null, StaffRole::Phlebotomist)->create();
        $token = $staff->createToken('api-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout-all')->assertNoContent();

        $this->assertSame(['all_devices' => true], AuditLog::query()->where('action', 'auth.signed_out')->sole()->context);
    }
}
