<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\FacilityStatus;
use App\Enums\FacilityTypeName;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\FacilityType;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Facility onboarding by the Super Admin.
 *
 * This is the path that replaced public self-registration, and both facility
 * types run through it. Where a test is written once for both, the data
 * provider is the point: a blood bank must not be a second-class case that
 * happens to work because a blood centre does.
 */
class FacilityManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        return User::factory()->withRole(RoleName::Admin)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'facility_type' => FacilityTypeName::BloodCenter->value,
            'name' => 'Davao Regional Blood Center',
            'doh_license_number' => 'DOH-BC-2026-00412',
            'address' => 'Quirino Ave, Davao City',
            'email' => 'info@drbc.ph',
            'phone' => '09171234567',
            'description' => 'Regional blood collection and processing centre.',
            'operating_hours' => 'Mon - Fri 8 AM - 3 PM',
            'slots_start_at' => '08:00',
            'slots_end_at' => '15:00',
            'primary_account' => [
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'position' => 'Blood Center Supervisor',
                'email' => 'maria.santos@drbc.ph',
                'phone' => '09181234567',
                'password' => 'SecurePass1',
                'password_confirmation' => 'SecurePass1',
            ],
        ], $overrides);
    }

    /**
     * A payload for the other facility type, with nothing else in common.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bloodBankPayload(array $overrides = []): array
    {
        return $this->payload(array_replace_recursive([
            'facility_type' => FacilityTypeName::BloodBank->value,
            'name' => 'SPMC Hospital Blood Bank',
            'doh_license_number' => 'DOH-BB-2026-00981',
            'email' => 'bloodbank@spmc.ph',
            'phone' => '09191234567',
            'primary_account' => [
                'position' => 'Chief Medical Technologist',
                'email' => 'chief.medtech@spmc.ph',
                'phone' => '09201234567',
            ],
        ], $overrides));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function facilityTypes(): array
    {
        return [
            'blood center' => ['blood_center'],
            'hospital blood bank' => ['blood_bank'],
        ];
    }

    // --- Creation -----------------------------------------------------------

    public function test_a_super_admin_can_create_an_active_blood_center(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/admin/facilities', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Davao Regional Blood Center')
            ->assertJsonPath('data.facility_type', 'blood_center')
            ->assertJsonPath('data.facility_type_label', 'Blood Center')
            ->assertJsonPath('data.status', FacilityStatus::Approved->value)
            ->assertJsonPath('data.primary_account.email', 'maria.santos@drbc.ph');

        $facility = Facility::where('doh_license_number', 'DOH-BC-2026-00412')->firstOrFail();

        $this->assertSame(FacilityStatus::Approved, $facility->status);
        $this->assertSame('blood_center', $facility->facilityType->name);
        $this->assertTrue($facility->is_accepting_donations);
    }

    public function test_a_super_admin_can_create_an_active_hospital_blood_bank(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->bloodBankPayload())
            ->assertCreated()
            ->assertJsonPath('data.facility_type', 'blood_bank')
            ->assertJsonPath('data.facility_type_label', 'Hospital Blood Bank')
            ->assertJsonPath('data.status', FacilityStatus::Approved->value);

        $facility = Facility::where('doh_license_number', 'DOH-BB-2026-00981')->firstOrFail();

        $this->assertSame('blood_bank', $facility->facilityType->name);

        // A blood bank draws on centres rather than collecting, so it is
        // created closed to donor bookings whatever the form sent.
        $this->assertFalse($facility->is_accepting_donations);
    }

    public function test_the_primary_account_holds_the_role_its_facility_type_carries(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/facilities', $this->payload())->assertCreated();
        $this->actingAs($admin)->postJson('/api/admin/facilities', $this->bloodBankPayload())->assertCreated();

        $centre = User::where('email', 'maria.santos@drbc.ph')->firstOrFail();
        $bank = User::where('email', 'chief.medtech@spmc.ph')->firstOrFail();

        $this->assertTrue($centre->hasRole(RoleName::BloodCenter));
        $this->assertFalse($centre->hasRole(RoleName::BloodBank));

        $this->assertTrue($bank->hasRole(RoleName::BloodBank));
        $this->assertFalse($bank->hasRole(RoleName::BloodCenter));
    }

    #[DataProvider('facilityTypes')]
    public function test_the_primary_account_is_linked_to_its_facility_and_holds_the_supervisor_level(string $type): void
    {
        $payload = $type === 'blood_bank' ? $this->bloodBankPayload() : $this->payload();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $payload)
            ->assertCreated()
            ->assertJsonPath('data.primary_account.is_supervisor', true);

        $facility = Facility::where('doh_license_number', $payload['doh_license_number'])->firstOrFail();
        $primary = User::where('email', $payload['primary_account']['email'])->firstOrFail();

        $this->assertSame($facility->id, $primary->facility_id);
        $this->assertTrue($primary->is_supervisor);

        // The supervisor level is what carries staff.manage, so the facility
        // has somebody able to build out its roster from day one.
        $this->assertContains('staff.manage', $primary->abilities());

        // And the facility records which account speaks for it.
        $this->assertSame($primary->id, $facility->registration_contact_user_id);
        $this->assertSame($primary->id, $facility->primaryAccount->id);
    }

    #[DataProvider('facilityTypes')]
    public function test_creation_records_the_approval_metadata(string $type): void
    {
        $admin = $this->admin();
        $payload = $type === 'blood_bank' ? $this->bloodBankPayload() : $this->payload();

        $this->actingAs($admin)->postJson('/api/admin/facilities', $payload)->assertCreated();

        $facility = Facility::where('doh_license_number', $payload['doh_license_number'])->firstOrFail();

        $this->assertSame(FacilityStatus::Approved, $facility->status);
        $this->assertNotNull($facility->approved_at);
        $this->assertSame($admin->id, $facility->approved_by);
        $this->assertNull($facility->rejection_reason);
    }

    public function test_the_primary_account_must_verify_its_email_before_signing_in(): void
    {
        Notification::fake();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.primary_account.email_verified', false);

        $primary = User::where('email', 'maria.santos@drbc.ph')->firstOrFail();

        $this->assertSame(AccountStatus::PendingVerification, $primary->account_status);
        $this->assertFalse($primary->hasVerifiedEmail());

        Notification::assertSentTo($primary, VerifyEmailNotification::class);
    }

    public function test_a_verified_primary_account_can_sign_in_to_its_own_portal(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->bloodBankPayload())
            ->assertCreated();

        $primary = User::where('email', 'chief.medtech@spmc.ph')->firstOrFail();
        $primary->forceFill(['email_verified_at' => now(), 'account_status' => AccountStatus::Active])->save();

        $this->postJson('/api/login', [
            'email' => 'chief.medtech@spmc.ph',
            'password' => 'SecurePass1',
            'role' => 'hospital',
        ])
            ->assertOk()
            ->assertJsonPath('user.roles', ['blood_bank'])
            ->assertJsonPath('user.facility.status', FacilityStatus::Approved->value);
    }

    public function test_creation_writes_an_audit_log_naming_the_admin_facility_type_and_primary_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/facilities', $this->bloodBankPayload())->assertCreated();

        $facility = Facility::where('doh_license_number', 'DOH-BB-2026-00981')->firstOrFail();
        $primary = User::where('email', 'chief.medtech@spmc.ph')->firstOrFail();

        $log = AuditLog::where('action', 'facility.created')->latest('id')->firstOrFail();

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($facility->id, (int) $log->auditable_id);
        $this->assertSame($admin->id, $log->context['super_admin_id']);
        $this->assertSame($facility->id, $log->context['facility_id']);
        $this->assertSame('blood_bank', $log->context['facility_type']);
        $this->assertSame($primary->id, $log->context['primary_account_id']);
    }

    public function test_the_phone_numbers_are_normalised_to_e164(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->payload([
                'phone' => '0917-123-4567',
                'primary_account' => ['phone' => '0918 123 4567'],
            ]))
            ->assertCreated();

        $this->assertDatabaseHas('facilities', ['phone' => '+639171234567']);
        $this->assertDatabaseHas('users', ['phone' => '+639181234567']);
    }

    public function test_the_primary_account_email_is_stored_in_lower_case_with_a_derived_username(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->payload([
                'primary_account' => ['email' => 'Maria.Santos@DRBC.ph'],
            ]))
            ->assertCreated();

        $primary = User::where('email', 'maria.santos@drbc.ph')->firstOrFail();

        $this->assertNotNull($primary->username);
        $this->assertStringStartsWith('maria.santos-', $primary->username);
    }

    public function test_a_supplied_username_that_is_taken_is_refused(): void
    {
        User::factory()->create(['username' => 'drbc-primary']);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->payload([
                'primary_account' => ['username' => 'drbc-primary'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_account.username');
    }

    // --- Untrusted input ----------------------------------------------------

    public function test_client_supplied_privilege_fields_are_ignored(): void
    {
        $admin = $this->admin();
        $otherAdmin = User::factory()->withRole(RoleName::Admin)->create();
        $bloodBankType = FacilityType::firstOrCreate(['name' => 'blood_bank']);
        $otherFacility = Facility::factory()->approved()->create();

        $this->actingAs($admin)
            ->postJson('/api/admin/facilities', $this->payload([
                // None of these may be reachable from the request body: they
                // decide which portal the account opens, which facility's stock
                // it can see, and who is on record as having cleared it.
                'facility_type_id' => $bloodBankType->id,
                'status' => FacilityStatus::Rejected->value,
                'approved_by' => $otherAdmin->id,
                'approved_at' => '2020-01-01T00:00:00+00:00',
                'registration_contact_user_id' => $otherAdmin->id,
                'primary_account' => [
                    'facility_id' => $otherFacility->id,
                    'is_supervisor' => false,
                    'department' => 'inventory',
                    'roles' => ['admin'],
                    'account_status' => AccountStatus::Active->value,
                    'email_verified_at' => '2020-01-01T00:00:00+00:00',
                ],
            ]))
            ->assertCreated();

        $facility = Facility::where('doh_license_number', 'DOH-BC-2026-00412')->firstOrFail();
        $primary = User::where('email', 'maria.santos@drbc.ph')->firstOrFail();

        $this->assertSame('blood_center', $facility->facilityType->name);
        $this->assertSame(FacilityStatus::Approved, $facility->status);
        $this->assertSame($admin->id, $facility->approved_by);
        $this->assertTrue($facility->approved_at->isToday());
        $this->assertSame($primary->id, $facility->registration_contact_user_id);

        $this->assertSame($facility->id, $primary->facility_id);
        $this->assertTrue($primary->is_supervisor);
        $this->assertNull($primary->department);
        $this->assertFalse($primary->hasRole(RoleName::Admin));
        $this->assertSame(AccountStatus::PendingVerification, $primary->account_status);
        $this->assertFalse($primary->hasVerifiedEmail());
    }

    // --- Validation ---------------------------------------------------------

    public function test_every_required_field_is_validated(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'facility_type', 'name', 'doh_license_number', 'address',
                'email', 'phone', 'primary_account',
            ]);
    }

    public function test_every_required_primary_account_field_is_validated(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', ['primary_account' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'primary_account.first_name', 'primary_account.last_name',
                'primary_account.position', 'primary_account.email',
                'primary_account.phone', 'primary_account.password',
            ]);
    }

    public function test_an_unknown_facility_type_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->payload(['facility_type' => 'laboratory']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('facility_type');
    }

    public function test_a_duplicate_doh_license_is_refused(): void
    {
        Facility::factory()->create(['doh_license_number' => 'DOH-BC-2026-00412']);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('doh_license_number');
    }

    public function test_a_duplicate_name_within_the_same_type_is_refused(): void
    {
        Facility::factory()->create(['name' => 'Davao Regional Blood Center']);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_the_same_name_is_allowed_under_a_different_facility_type(): void
    {
        // facilities carries unique(facility_type_id, name): a hospital blood
        // bank and a blood centre may legitimately share a name.
        Facility::factory()->create(['name' => 'San Pedro']);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->bloodBankPayload(['name' => 'San Pedro']))
            ->assertCreated();
    }

    public function test_a_duplicate_facility_email_is_refused(): void
    {
        Facility::factory()->create(['email' => 'info@drbc.ph']);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_a_duplicate_primary_account_email_is_refused(): void
    {
        User::factory()->create(['email' => 'maria.santos@drbc.ph']);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_account.email');
    }

    public function test_a_duplicate_primary_account_phone_is_refused_after_normalisation(): void
    {
        User::factory()->create(['phone' => '+639181234567']);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->payload([
                'primary_account' => ['phone' => '09181234567'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_account.phone');
    }

    public function test_a_weak_primary_account_password_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->payload([
                'primary_account' => ['password' => 'password', 'password_confirmation' => 'password'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_account.password');
    }

    public function test_a_mismatched_password_confirmation_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/facilities', $this->payload([
                'primary_account' => ['password_confirmation' => 'SomethingElse1'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_account.password');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function partialFailures(): array
    {
        return [
            'duplicate account email' => [['primary_account' => ['email' => 'taken@example.ph']], 'primary_account.email'],
            'invalid facility type' => [['facility_type' => 'laboratory'], 'facility_type'],
            'weak password' => [['primary_account' => ['password' => 'weak', 'password_confirmation' => 'weak']], 'primary_account.password'],
        ];
    }

    /**
     * A rejected submission leaves neither half of the pair behind.
     *
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('partialFailures')]
    public function test_a_failed_creation_leaves_no_partial_records(array $overrides, string $field): void
    {
        User::factory()->create(['email' => 'taken@example.ph']);
        $admin = $this->admin();

        $facilitiesBefore = Facility::count();
        $usersBefore = User::count();

        $this->actingAs($admin)
            ->postJson('/api/admin/facilities', $this->payload($overrides))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertSame($facilitiesBefore, Facility::count());
        $this->assertSame($usersBefore, User::count());
        $this->assertDatabaseMissing('facilities', ['doh_license_number' => 'DOH-BC-2026-00412']);
    }

    // --- Authorization ------------------------------------------------------

    public function test_creation_refuses_an_unauthenticated_caller(): void
    {
        $this->postJson('/api/admin/facilities', $this->payload())->assertUnauthorized();

        $this->assertDatabaseMissing('facilities', ['doh_license_number' => 'DOH-BC-2026-00412']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAdminActors(): array
    {
        return [
            'donor' => ['donor'],
            'blood center supervisor' => ['blood_center_supervisor'],
            'blood bank staff' => ['blood_bank'],
            'roleless account' => ['none'],
        ];
    }

    #[DataProvider('nonAdminActors')]
    public function test_creation_and_listing_refuse_every_non_admin(string $kind): void
    {
        $actor = match ($kind) {
            'donor' => User::factory()->donor()->create(),
            'blood_center_supervisor' => User::factory()->bloodCenterSupervisor()->create(),
            'blood_bank' => User::factory()
                ->withRole(RoleName::BloodBank)
                ->create(['facility_id' => Facility::factory()->bloodBank()->approved()->create()->id]),
            default => User::factory()->create(),
        };

        $this->actingAs($actor)->getJson('/api/admin/facilities')->assertForbidden();
        $this->actingAs($actor)
            ->postJson('/api/admin/facilities', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseMissing('facilities', ['doh_license_number' => 'DOH-BC-2026-00412']);
    }

    // --- Listing ------------------------------------------------------------

    public function test_the_list_carries_the_facility_type_primary_account_and_status(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/facilities', $this->payload())->assertCreated();

        $row = collect($this->actingAs($this->admin())->getJson('/api/admin/facilities')->assertOk()->json('data'))
            ->firstWhere('doh_license_number', 'DOH-BC-2026-00412');

        $this->assertNotNull($row);
        $this->assertSame('blood_center', $row['facility_type']);
        $this->assertSame('Blood Center', $row['facility_type_label']);
        $this->assertSame(FacilityStatus::Approved->value, $row['status']);
        $this->assertSame('maria.santos@drbc.ph', $row['primary_account']['email']);
        $this->assertSame('Maria Santos', $row['primary_account']['full_name']);
        $this->assertTrue($row['primary_account']['is_supervisor']);
    }

    public function test_the_list_can_filter_by_facility_type(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/facilities', $this->payload())->assertCreated();
        $this->actingAs($admin)->postJson('/api/admin/facilities', $this->bloodBankPayload())->assertCreated();

        $types = array_column(
            $this->actingAs($admin)->getJson('/api/admin/facilities?facility_type=blood_bank')->assertOk()->json('data'),
            'facility_type'
        );

        $this->assertNotEmpty($types);
        $this->assertSame(['blood_bank'], array_values(array_unique($types)));
    }

    public function test_the_list_rejects_an_unknown_facility_type_filter(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/facilities?facility_type=laboratory')
            ->assertStatus(422)
            ->assertJsonValidationErrors('facility_type');
    }
}
