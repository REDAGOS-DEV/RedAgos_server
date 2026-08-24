<?php

namespace Tests\Feature\BloodCenter;

use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_returns_the_callers_own_profile_and_facility(): void
    {
        $facility = Facility::factory()->approved()->create(['name' => 'My Center']);
        $staff = User::factory()->bloodCenterStaff($facility)->create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);

        $this->actingAs($staff)
            ->getJson('/api/blood-center/profile')
            ->assertOk()
            ->assertJsonPath('profile.full_name', 'Maria Santos')
            ->assertJsonPath('profile.position', 'Medical Technologist')
            ->assertJsonPath('facility.name', 'My Center')
            ->assertJsonPath('account.roles', ['blood_center']);
    }

    public function test_it_never_returns_another_facility(): void
    {
        $mine = Facility::factory()->approved()->create(['name' => 'My Center']);
        Facility::factory()->approved()->create(['name' => 'Other Center']);

        $staff = User::factory()->bloodCenterStaff($mine)->create();

        // Resolved from the token, never from request input, so there is no id
        // for a caller to tamper with.
        $this->actingAs($staff)
            ->getJson('/api/blood-center/profile')
            ->assertOk()
            ->assertJsonPath('facility.id', $mine->id);
    }

    public function test_staff_can_update_their_own_details(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();

        $this->actingAs($staff)
            ->patchJson('/api/blood-center/profile', [
                'first_name' => 'Updated',
                'employee_id' => 'EMP-001',
                'position' => 'Registered Nurse',
            ])
            ->assertOk()
            ->assertJsonPath('profile.first_name', 'Updated')
            ->assertJsonPath('profile.employee_id', 'EMP-001')
            ->assertJsonPath('profile.position', 'Registered Nurse');
    }

    public function test_the_profile_endpoint_cannot_change_the_facility(): void
    {
        $mine = Facility::factory()->approved()->create();
        $other = Facility::factory()->approved()->create();
        $staff = User::factory()->bloodCenterStaff($mine)->create();

        // facility_id is absent from both the request rules and User's fillable
        // list, so this is ignored twice over.
        $this->actingAs($staff)
            ->patchJson('/api/blood-center/profile', [
                'first_name' => 'Updated',
                'facility_id' => $other->id,
                'doh_license_number' => 'DOH-FAKE-1',
            ])
            ->assertOk();

        $this->assertSame($mine->id, $staff->fresh()->facility_id);
        $this->assertNotSame('DOH-FAKE-1', $mine->fresh()->doh_license_number);
    }

    public function test_an_employee_id_must_be_unique_within_the_facility(): void
    {
        $facility = Facility::factory()->approved()->create();
        User::factory()->bloodCenterStaff($facility)->create(['employee_id' => 'EMP-001']);
        $other = User::factory()->bloodCenterStaff($facility)->create();

        $this->actingAs($other)
            ->patchJson('/api/blood-center/profile', ['employee_id' => 'EMP-001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_the_same_employee_id_is_allowed_at_a_different_facility(): void
    {
        $first = Facility::factory()->approved()->create();
        User::factory()->bloodCenterStaff($first)->create(['employee_id' => 'EMP-001']);

        $second = Facility::factory()->approved()->create();
        $staff = User::factory()->bloodCenterStaff($second)->create();

        // Mirrors unique(facility_id, employee_id): a badge number only has to
        // be unique inside its own facility.
        $this->actingAs($staff)
            ->patchJson('/api/blood-center/profile', ['employee_id' => 'EMP-001'])
            ->assertOk();
    }

    public function test_a_password_change_revokes_every_token(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();
        $staff->createToken('blood_center-token');
        $staff->createToken('another-device');

        $this->assertSame(2, $staff->tokens()->count());

        $this->actingAs($staff)
            ->postJson('/api/blood-center/password', [
                'current_password' => 'password',
                'password' => 'BrandNewPass1',
                'password_confirmation' => 'BrandNewPass1',
            ])
            ->assertOk();

        $this->assertSame(0, $staff->fresh()->tokens()->count());
        $this->assertTrue(Hash::check('BrandNewPass1', $staff->fresh()->password));
    }

    public function test_a_wrong_current_password_is_refused(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();

        $this->actingAs($staff)
            ->postJson('/api/blood-center/password', [
                'current_password' => 'not-the-password',
                'password' => 'BrandNewPass1',
                'password_confirmation' => 'BrandNewPass1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    public function test_the_new_password_must_meet_the_strength_policy(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();

        $this->actingAs($staff)
            ->postJson('/api/blood-center/password', [
                'current_password' => 'password',
                'password' => 'weak',
                'password_confirmation' => 'weak',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_the_profile_endpoints_reject_a_donor(): void
    {
        $donor = User::factory()->donor()->create();

        $this->actingAs($donor)->getJson('/api/blood-center/profile')->assertForbidden();
        $this->actingAs($donor)->patchJson('/api/blood-center/profile', [])->assertForbidden();
    }

    public function test_the_profile_endpoints_reject_unauthenticated_callers(): void
    {
        $this->getJson('/api/blood-center/profile')->assertUnauthorized();
        $this->patchJson('/api/blood-center/profile', [])->assertUnauthorized();
        $this->postJson('/api/blood-center/password', [])->assertUnauthorized();
    }
}
