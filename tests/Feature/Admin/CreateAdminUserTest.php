<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The console bootstrap for the first administrator.
 *
 * The API refuses to mint an unrestricted admin unless the caller already is
 * one, so on an empty database this command is the only door in. What these
 * tests are really asserting is that the account it produces can sign in: an
 * admin row that login rejects for an unverified address would look created and
 * be useless.
 */
class CreateAdminUserTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @var array<string, string>
     */
    private const VALID = [
        '--first-name' => 'Rafael',
        '--last-name' => 'Ababa',
        '--email' => 'admin@redagos.test',
        '--username' => 'superadmin',
        '--password' => 'Sup3rSecret',
    ];

    public function test_it_creates_an_unrestricted_administrator(): void
    {
        $this->artisan('admin:create', self::VALID)->assertSuccessful();

        $admin = User::where('email', 'admin@redagos.test')->sole();

        $this->assertTrue($admin->is_super_admin);
        $this->assertTrue($admin->hasRole(RoleName::Admin));
        $this->assertSame(AccountStatus::Active, $admin->account_status);
        $this->assertNotNull($admin->uuid);
        $this->assertNotNull($admin->activated_at);
    }

    public function test_the_created_administrator_can_sign_in(): void
    {
        $this->artisan('admin:create', self::VALID)->assertSuccessful();

        $this->postJson('/api/login', [
            'email' => 'admin@redagos.test',
            'password' => 'Sup3rSecret',
            'role' => 'admin',
        ])->assertOk()->assertJsonPath('user.email', 'admin@redagos.test');
    }

    public function test_it_lower_cases_the_sign_in_address(): void
    {
        $this->artisan('admin:create', [...self::VALID, '--email' => 'Admin@RedAgos.test'])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'admin@redagos.test']);
    }

    public function test_scoped_grants_only_the_named_privileges(): void
    {
        $this->artisan('admin:create', [
            ...self::VALID,
            '--scoped' => ['admin.donor.view', 'admin.audit.view'],
        ])->assertSuccessful();

        $admin = User::where('email', 'admin@redagos.test')->sole();

        $this->assertFalse($admin->is_super_admin);
        $this->assertTrue($admin->hasRole(RoleName::Admin));
        $this->assertEqualsCanonicalizing(
            ['admin.donor.view', 'admin.audit.view'],
            $admin->abilities()
        );
    }

    public function test_it_refuses_an_unknown_privilege_without_creating_anything(): void
    {
        $this->artisan('admin:create', [...self::VALID, '--scoped' => ['admin.everything']])
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_a_password_that_fails_the_application_policy(): void
    {
        $this->artisan('admin:create', [...self::VALID, '--password' => 'password'])
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_an_email_already_in_use(): void
    {
        User::factory()->donor()->create(['email' => 'admin@redagos.test']);

        $this->artisan('admin:create', self::VALID)->assertFailed();

        $this->assertSame(0, Role::where('name', RoleName::Admin->value)->first()?->users()->count() ?? 0);
    }

    public function test_a_field_left_blank_at_the_prompt_creates_nothing(): void
    {
        $options = self::VALID;
        unset($options['--email']);

        $this->artisan('admin:create', $options)
            ->expectsQuestion('Email', '')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_a_mistyped_password_confirmation(): void
    {
        $options = self::VALID;
        unset($options['--password']);

        $this->artisan('admin:create', $options)
            ->expectsQuestion('Password', 'Sup3rSecret')
            ->expectsQuestion('Confirm password', 'Sup3rSecrets')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
