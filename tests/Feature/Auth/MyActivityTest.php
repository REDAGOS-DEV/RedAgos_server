<?php

namespace Tests\Feature\Auth;

use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * My activity (GET /api/me/activity): every staff user's own audit trail,
 * in any portal. Theirs alone: a colleague's rows never show, and the
 * sign-ins refused on their account do.
 */
class MyActivityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
    }

    private function log(?User $actor, string $action, ?User $subject = null, array $extra = []): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'facility_id' => $this->facility->id,
            'action' => $action,
            'auditable_type' => $subject ? User::class : null,
            'auditable_id' => $subject ? (string) $subject->id : null,
            'ip_address' => '203.177.10.4',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
            ...$extra,
        ]);
    }

    public function test_lists_only_the_users_own_rows_and_refused_sign_ins_on_their_account(): void
    {
        $colleague = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create();

        $mine = $this->log($this->staff, 'auth.signed_in', $this->staff);
        $refused = $this->log(null, 'auth.sign_in_failed', $this->staff, ['context' => ['reason' => 'wrong_password']]);
        $this->log($colleague, 'auth.signed_in', $colleague);
        // Refused on the colleague's account: not this user's.
        $this->log(null, 'auth.sign_in_failed', $colleague);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/me/activity')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 2);

        $this->assertEqualsCanonicalizing([$mine->id, $refused->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_sign_in_rows_say_where_they_came_from(): void
    {
        $this->log($this->staff, 'auth.signed_in', $this->staff);

        $this->actingAs($this->staff)
            ->getJson('/api/me/activity')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'sign_in')
            ->assertJsonPath('data.0.label', 'Signed in')
            // Their own account, and a detail that only repeats the action, go unsaid.
            ->assertJsonPath('data.0.record', null)
            ->assertJsonPath('data.0.details', '')
            ->assertJsonPath('data.0.device', 'Chrome on Windows')
            ->assertJsonPath('data.0.ip_address', '203.177.10.4');
    }

    public function test_each_row_carries_its_core_audit_fields(): void
    {
        $this->log($this->staff, 'auth.signed_in', $this->staff, ['context' => ['portal' => 'blood_center', 'via' => 'google']]);
        $this->log(null, 'auth.sign_in_failed', $this->staff, ['context' => ['reason' => 'wrong_password']]);
        $this->log($this->staff, 'staff.role_changed', null, ['context' => ['from' => 'phlebotomist', 'to' => 'medical_receptionist']]);

        $rows = collect($this->actingAs($this->staff)->getJson('/api/me/activity')->assertOk()->json('data'))->keyBy('action');

        $signedIn = $rows['auth.signed_in']['audit'];
        $this->assertSame('auth.signed_in', $signedIn['event']);
        $this->assertSame(['kind' => 'user', 'id' => $this->staff->id, 'reference' => $this->staff->employee_id], $signedIn['actor']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $signedIn['timestamp']);
        $this->assertSame(['type' => 'User', 'id' => (string) $this->staff->id, 'is_you' => true], $signedIn['target']);
        $this->assertSame('203.177.10.4', $signedIn['origin']['ip_address']);
        $this->assertSame('Chrome on Windows', $signedIn['origin']['device']);
        $this->assertSame('success', $signedIn['outcome']['status']);
        $this->assertSame(['method' => 'google', 'portal' => 'blood_center'], $signedIn['auth']);

        $refused = $rows['auth.sign_in_failed']['audit'];
        $this->assertSame('unauthenticated', $refused['actor']['kind']);
        $this->assertSame(['status' => 'failure', 'code' => 'wrong_password', 'reason' => 'wrong password'], $refused['outcome']);

        $changed = $rows['staff.role_changed']['audit'];
        $this->assertSame([['field' => 'value', 'from' => 'phlebotomist', 'to' => 'medical_receptionist']], $changed['changes']);
        $this->assertSame('session', $changed['auth']['method']);
    }

    public function test_filters_by_kind_of_action(): void
    {
        $this->log($this->staff, 'auth.signed_in', $this->staff);
        $this->log($this->staff, 'auth.signed_out', $this->staff);

        $this->actingAs($this->staff)
            ->getJson('/api/me/activity?type=sign_out')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'sign_out');

        $this->actingAs($this->staff)
            ->getJson('/api/me/activity?type=nonsense')
            ->assertUnprocessable();
    }

    public function test_a_hospital_user_sees_their_own_too(): void
    {
        $hospital = User::factory()->bloodBankStaff()->create();
        AuditLog::query()->create([
            'actor_id' => $hospital->id,
            'facility_id' => $hospital->facility_id,
            'action' => 'auth.signed_in',
            'auditable_type' => User::class,
            'auditable_id' => (string) $hospital->id,
        ]);
        $this->log($this->staff, 'auth.signed_in', $this->staff);

        $this->actingAs($hospital)
            ->getJson('/api/me/activity')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/me/activity')->assertUnauthorized();
    }
}
