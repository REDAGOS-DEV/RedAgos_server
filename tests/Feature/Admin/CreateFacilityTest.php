<?php

namespace Tests\Feature\Admin;

use App\Enums\FacilityStatus;
use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The console counterpart to facility onboarding.
 *
 * What these tests hold the command to is that it is the same operation as the
 * admin endpoint rather than a looser second door onto the facilities table:
 * the approval trail names a real administrator, the role follows the facility
 * type, and an account it creates can actually sign in once verified.
 */
class CreateFacilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @var array<string, string>
     */
    private const FACILITY = [
        '--name' => 'Davao Regional Blood Center',
        '--doh-license' => 'DOH-BC-99887766',
        '--address' => 'Davao City',
        '--email' => 'center@redagos.test',
        '--phone' => '09171234567',
        '--account-first-name' => 'Maria',
        '--account-last-name' => 'Santos',
        '--account-position' => 'Blood Center Supervisor',
        '--account-email' => 'maria@redagos.test',
        '--account-phone' => '09181234567',
        '--account-password' => 'Sup3rSecret',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->withRole(RoleName::Admin)->create();
    }

    public function test_it_creates_an_approved_facility_stamped_with_the_administrator(): void
    {
        $this->artisan('facility:create', $this->commandOptions())->assertSuccessful();

        $facility = Facility::where('name', 'Davao Regional Blood Center')->sole();

        $this->assertSame(FacilityStatus::Approved, $facility->status);
        $this->assertSame($this->admin->id, $facility->approved_by);
        $this->assertNotNull($facility->approved_at);
    }

    public function test_the_primary_account_runs_the_facility_portal(): void
    {
        $this->artisan('facility:create', $this->commandOptions())->assertSuccessful();

        $facility = Facility::where('name', 'Davao Regional Blood Center')->sole();
        $primary = User::where('email', 'maria@redagos.test')->sole();

        $this->assertSame($facility->id, $primary->facility_id);
        $this->assertTrue($primary->is_supervisor);
        $this->assertNull($primary->department);
        $this->assertTrue($primary->hasRole(RoleName::BloodCenter));
        $this->assertSame($primary->id, $facility->registration_contact_user_id);
    }

    public function test_a_blood_bank_gets_the_blood_bank_role_and_takes_no_bookings(): void
    {
        $this->artisan('facility:create', $this->commandOptions(['--type' => 'blood_bank']))
            ->assertSuccessful();

        $facility = Facility::where('name', 'Davao Regional Blood Center')->sole();

        $this->assertFalse((bool) $facility->is_accepting_donations);
        $this->assertTrue(User::where('email', 'maria@redagos.test')->sole()->hasRole(RoleName::BloodBank));
    }

    public function test_it_stores_phone_numbers_in_the_form_the_columns_hold(): void
    {
        $this->artisan('facility:create', $this->commandOptions())->assertSuccessful();

        $this->assertDatabaseHas('facilities', ['phone' => '+639171234567']);
        $this->assertDatabaseHas('users', ['phone' => '+639181234567']);
    }

    public function test_verified_produces_an_account_that_can_sign_in_at_once(): void
    {
        $this->artisan('facility:create', $this->commandOptions(['--verified' => true]))
            ->assertSuccessful();

        $this->postJson('/api/login', [
            'email' => 'maria@redagos.test',
            'password' => 'Sup3rSecret',
            'role' => 'blood_center',
        ])->assertOk();
    }

    public function test_without_verified_the_account_waits_on_the_emailed_link(): void
    {
        $this->artisan('facility:create', $this->commandOptions())->assertSuccessful();

        $this->postJson('/api/login', [
            'email' => 'maria@redagos.test',
            'password' => 'Sup3rSecret',
            'role' => 'blood_center',
        ])->assertForbidden()->assertJsonPath('code', 'email_not_verified');
    }

    public function test_it_refuses_to_run_with_no_administrator_to_record(): void
    {
        $this->admin->roles()->detach();

        $this->artisan('facility:create', $this->commandOptions())->assertFailed();

        $this->assertDatabaseCount('facilities', 0);
    }

    public function test_it_refuses_to_guess_between_several_administrators(): void
    {
        User::factory()->withRole(RoleName::Admin)->create();

        $this->artisan('facility:create', $this->commandOptions())->assertFailed();

        $this->assertDatabaseCount('facilities', 0);
    }

    public function test_admin_names_which_administrator_is_recorded(): void
    {
        $second = User::factory()->withRole(RoleName::Admin)->create();

        $this->artisan('facility:create', $this->commandOptions(['--admin' => $second->email]))
            ->assertSuccessful();

        $this->assertSame(
            $second->id,
            Facility::where('name', 'Davao Regional Blood Center')->sole()->approved_by
        );
    }

    public function test_a_licence_number_already_on_file_creates_nothing(): void
    {
        Facility::factory()->approved()->create(['doh_license_number' => 'DOH-BC-99887766']);

        $this->artisan('facility:create', $this->commandOptions())->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'maria@redagos.test']);
        $this->assertDatabaseMissing('facilities', ['name' => 'Davao Regional Blood Center']);
    }

    public function test_a_malformed_phone_number_creates_nothing(): void
    {
        $this->artisan('facility:create', $this->commandOptions(['--phone' => '12345']))->assertFailed();

        $this->assertDatabaseMissing('facilities', ['name' => 'Davao Regional Blood Center']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commandOptions(array $overrides = []): array
    {
        return ['--type' => 'blood_center', ...self::FACILITY, ...$overrides];
    }
}
