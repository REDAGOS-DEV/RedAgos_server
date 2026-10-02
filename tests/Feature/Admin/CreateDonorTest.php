<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\RoleName;
use App\Models\BloodType;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The console counterpart to donor self-registration.
 *
 * It is held to the same rules as the sign-up form, and differs in exactly one
 * way: the account comes out verified and active, with no link mailed, because
 * the operator at the terminal is vouching for it.
 */
class CreateDonorTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @var array<string, string>
     */
    private const DONOR = [
        '--first-name' => 'Juan',
        '--last-name' => 'Dela Cruz',
        '--email' => 'juan@redagos.test',
        '--phone' => '09171234567',
        '--gender' => 'male',
        '--birth-date' => '1995-05-20',
        '--address' => '123 Rizal Street, Davao City',
        '--blood-type' => 'o+',
        '--password' => 'Sup3rSecret',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Idempotent: DonorProfileFactory also creates random blood types from an
        // eight-element pool, so an explicit create collides whenever they match.
        BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
    }

    public function test_it_registers_a_verified_donor_without_mailing_a_link(): void
    {
        Notification::fake();

        $this->artisan('donor:create', self::DONOR)->assertSuccessful();

        Notification::assertNothingSent();

        $donor = User::where('email', 'juan@redagos.test')->sole();

        $this->assertTrue($donor->hasRole(RoleName::Donor));
        $this->assertTrue($donor->hasVerifiedEmail());
        $this->assertSame(AccountStatus::Active, $donor->account_status);
        $this->assertNotNull($donor->activated_at);
        $this->assertNotNull($donor->terms_accepted_at);
        $this->assertSame('+639171234567', $donor->phone);
        $this->assertSame('O+', $donor->donorProfile->bloodType->code);
    }

    public function test_the_donor_can_sign_in_at_once(): void
    {
        $this->artisan('donor:create', self::DONOR)->assertSuccessful();

        $this->postJson('/api/login', [
            'email' => 'juan@redagos.test',
            'password' => 'Sup3rSecret',
            'role' => RoleName::Donor->value,
        ])->assertOk();
    }

    public function test_the_blood_type_may_be_left_unknown(): void
    {
        $options = self::DONOR;
        unset($options['--blood-type']);

        $this->artisan('donor:create', $options)->assertSuccessful();

        $this->assertNull(User::where('email', 'juan@redagos.test')->sole()->donorProfile->blood_type_id);
    }

    public function test_the_registration_age_limit_still_applies(): void
    {
        $this->artisan('donor:create', [
            ...self::DONOR,
            '--birth-date' => now()->subYears(17)->toDateString(),
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'juan@redagos.test']);
    }

    public function test_a_registered_phone_number_is_refused_in_any_form(): void
    {
        $this->artisan('donor:create', self::DONOR)->assertSuccessful();

        // Stored as +639171234567; the same number typed the local way must
        // still be caught by the unique rule, not by the database.
        $this->artisan('donor:create', [
            ...self::DONOR,
            '--email' => 'second@redagos.test',
            '--phone' => '0917 123 4567',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'second@redagos.test']);
    }
}
