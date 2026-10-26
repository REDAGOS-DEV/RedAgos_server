<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AccountStatus;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The counter's sign-in slip for a walk-in donor (project owner, 2026-10-21):
 * the donor code or email and a temporary password, so the donor signs in to
 * the app without registering.
 */
class CounterSignInTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
    }

    public function test_the_desk_issues_a_slip_for_a_walk_in(): void
    {
        $donor = $this->registerWalkIn();

        $response = $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/sign-in")
            ->assertOk()
            ->assertJsonPath('data.donor_code', AccountIdentity::donorCode($donor->id))
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.reissued', false);

        $password = $response->json('data.temporary_password');
        $donor->refresh();

        // Ten characters, none of them a look-alike.
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-np-z2-9]{10}$/', $password);
        $this->assertTrue(Hash::check($password, $donor->password));
        $this->assertTrue($donor->password_change_required);
        $this->assertNotNull($donor->sign_in_issued_at);

        $row = AuditLog::query()->where('action', 'donor.sign_in_issued')->sole();

        $this->assertSame(['facility_id' => $this->facility->id, 'reissued' => false], $row->context);
        $this->assertStringNotContainsString($password, json_encode($row->context));
    }

    public function test_the_donor_signs_in_with_the_donor_code_and_no_email(): void
    {
        $donor = $this->registerWalkIn();
        $password = $this->issueSlip($donor);

        $this->postJson('/api/login', [
            'login' => strtolower(AccountIdentity::donorCode($donor->id)),
            'password' => $password,
        ])
            ->assertOk()
            ->assertJsonPath('user.uuid', $donor->uuid)
            ->assertJsonPath('user.temporary_sign_in', true)
            ->assertJsonPath('user.email_verified', false);
    }

    public function test_the_donor_signs_in_with_the_typed_email_before_it_is_confirmed(): void
    {
        $donor = $this->registerWalkIn(['email' => 'ana.reyes@example.com']);
        $password = $this->issueSlip($donor);

        $this->postJson('/api/login', ['login' => 'ana.reyes@example.com', 'password' => $password])
            ->assertOk()
            ->assertJsonPath('user.email_verified', false);
    }

    public function test_only_the_password_change_is_open_on_the_temporary_password(): void
    {
        $donor = $this->registerWalkIn();
        $token = $this->signIn($donor, $this->issueSlip($donor));

        $this->withToken($token)->getJson('/api/donors/donations')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        $this->withToken($token)->getJson('/api/donors/profile')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.temporary_sign_in', true);

        $this->withToken($token)->postJson('/api/logout')->assertNoContent();
    }

    public function test_setting_a_password_claims_the_account_and_ends_other_sessions(): void
    {
        $donor = $this->registerWalkIn();
        $temporary = $this->issueSlip($donor);
        $elsewhere = $this->signIn($donor, $temporary);
        $token = $this->signIn($donor, $temporary);

        // The slip's password is replaced, never kept.
        $this->withToken($token)->postJson('/api/donors/password', [
            'current_password' => $temporary,
            'password' => $temporary,
            'password_confirmation' => $temporary,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->withToken($token)->postJson('/api/donors/password', [
            'current_password' => $temporary,
            'password' => 'MyOwnPass123',
            'password_confirmation' => 'MyOwnPass123',
        ])->assertOk();

        $donor->refresh();

        $this->assertFalse($donor->password_change_required);
        $this->assertSame(AccountStatus::Active, $donor->account_status);
        $this->assertNotNull($donor->activated_at);
        $this->assertSame(1, $donor->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/donors/donations')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($elsewhere)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_the_temporary_password_does_not_expire(): void
    {
        $donor = $this->registerWalkIn();
        $password = $this->issueSlip($donor);

        $this->travel(1)->years();

        $this->postJson('/api/login', ['login' => AccountIdentity::donorCode($donor->id), 'password' => $password])
            ->assertOk();
    }

    public function test_issuing_again_replaces_the_slip_and_ends_its_sessions(): void
    {
        $donor = $this->registerWalkIn();
        $first = $this->issueSlip($donor);
        $token = $this->signIn($donor, $first);

        $second = $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/sign-in")
            ->assertOk()
            ->assertJsonPath('data.reissued', true)
            ->json('data.temporary_password');

        $this->app['auth']->forgetGuards();

        $this->assertNotSame($first, $second);
        $this->assertSame(0, $donor->tokens()->count());
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();

        $code = AccountIdentity::donorCode($donor->id);
        $this->postJson('/api/login', ['login' => $code, 'password' => $first])->assertStatus(422);
        $this->postJson('/api/login', ['login' => $code, 'password' => $second])->assertOk();
    }

    public function test_a_donor_with_a_confirmed_address_resets_their_own_password(): void
    {
        $donor = $this->registerWalkIn(['email' => 'ana.reyes@example.com']);
        $donor->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/sign-in")
            ->assertStatus(409)
            ->assertJsonPath('code', 'sign_in_managed_by_donor');

        $this->assertFalse($donor->fresh()->password_change_required);
    }

    public function test_a_suspended_donor_is_not_given_a_slip(): void
    {
        $donor = $this->registerWalkIn();
        $donor->forceFill(['account_status' => AccountStatus::Suspended])->save();

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/sign-in")
            ->assertStatus(409)
            ->assertJsonPath('code', 'account_not_active');
    }

    public function test_another_centre_cannot_issue_a_slip_for_this_centres_donor(): void
    {
        $donor = $this->registerWalkIn();
        $elsewhere = User::factory()
            ->bloodCenterStaff(Facility::factory()->approved()->create(), StaffRole::MedicalReceptionist)
            ->create();

        $this->actingAs($elsewhere)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/sign-in")
            ->assertForbidden()
            ->assertJsonPath('code', 'donor_not_at_facility');
    }

    public function test_staff_without_donors_manage_cannot_issue_a_slip(): void
    {
        $donor = $this->registerWalkIn();
        $phlebotomist = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create();

        $this->actingAs($phlebotomist)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/sign-in")
            ->assertForbidden();

        $this->assertFalse($donor->fresh()->password_change_required);
    }

    public function test_a_staff_accounts_id_is_not_a_sign_in_name(): void
    {
        $colleague = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create([
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', ['login' => AccountIdentity::donorCode($colleague->id), 'password' => 'Password123'])
            ->assertStatus(422);
    }

    public function test_the_desk_sees_where_the_slip_stands(): void
    {
        $donor = $this->registerWalkIn();

        $this->actingAs($this->staff)->getJson("/api/blood-center/donors/{$donor->uuid}")
            ->assertOk()
            ->assertJsonPath('sign_in.issued_at', null)
            ->assertJsonPath('sign_in.claimed', false)
            ->assertJsonPath('sign_in.can_issue', true);

        $temporary = $this->issueSlip($donor);
        $token = $this->signIn($donor, $temporary);

        $this->withToken($token)->postJson('/api/donors/password', [
            'current_password' => $temporary,
            'password' => 'MyOwnPass123',
            'password_confirmation' => 'MyOwnPass123',
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->actingAs($this->staff)->getJson("/api/blood-center/donors/{$donor->uuid}")
            ->assertOk()
            ->assertJsonPath('sign_in.claimed', true)
            ->assertJsonPath('sign_in.can_issue', true)
            // Claimed: the account is the donor's, so the desk no longer edits
            // their contact details.
            ->assertJsonPath('editable.contact', false);

        $this->assertNotNull($donor->fresh()->sign_in_issued_at);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function registerWalkIn(array $overrides = []): User
    {
        $response = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/donors', [
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'valid_id_number' => 'PH-DL-'.random_int(10000, 99999),
                'birth_date' => now()->subYears(30)->toDateString(),
                ...$overrides,
            ])
            ->assertCreated();

        $this->app['auth']->forgetGuards();

        return User::where('uuid', $response->json('data.uuid'))->firstOrFail();
    }

    private function issueSlip(User $donor): string
    {
        $password = $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/sign-in")
            ->assertOk()
            ->json('data.temporary_password');

        // actingAs() sets the session guard, which Sanctum reads before any
        // bearer token: forget it, so the donor's own token is what counts.
        $this->app['auth']->forgetGuards();

        return $password;
    }

    private function signIn(User $donor, string $password): string
    {
        $token = $this->postJson('/api/login', [
            'login' => AccountIdentity::donorCode($donor->id),
            'password' => $password,
        ])
            ->assertOk()
            ->json('token');

        $this->app['auth']->forgetGuards();

        return $token;
    }
}
