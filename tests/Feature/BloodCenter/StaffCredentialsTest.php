<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\StaffRole;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A staff member's credentials, such as RMT, MD or RN, printed after their
 * name wherever any department says who did something (project owner,
 * 2026-10-22).
 */
class StaffCredentialsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->admin = User::factory()->bloodCenterAdmin($this->facility)->create();
    }

    public function test_the_name_signs_with_the_credentials_when_there_are_any(): void
    {
        $user = User::factory()->make(['first_name' => 'Rowena', 'last_name' => 'Tan', 'credentials' => 'RMT']);

        $this->assertSame('Rowena Tan, RMT', $user->signatureName());

        $user->credentials = null;
        $this->assertSame('Rowena Tan', $user->signatureName());
        $this->assertNull(User::signatureOf(null));
    }

    public function test_the_admin_sets_and_clears_a_colleagues_credentials(): void
    {
        $uuid = $this->actingAs($this->admin)
            ->postJson('/api/blood-center/staff', [
                'first_name' => 'Rowena',
                'last_name' => 'Tan',
                'email' => 'rowena.tan@example.com',
                'phone' => '09171234567',
                // Ignored: blood-centre accounts keep a title, not a job post.
                'position' => 'Inventory Control Officer',
                'credentials' => 'RMT',
                'staff_role' => StaffRole::InventoryControlOfficer->value,
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.credentials', 'RMT')
            ->assertJsonPath('data.signature_name', 'Rowena Tan, RMT')
            ->assertJsonMissingPath('data.position')
            ->json('data.uuid');

        $this->assertNull(User::where('uuid', $uuid)->value('position'));

        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$uuid}", ['credentials' => 'RMT, MSc'])
            ->assertOk();
        $this->assertSame('RMT, MSc', User::where('uuid', $uuid)->value('credentials'));

        $this->actingAs($this->admin)
            ->patchJson("/api/blood-center/staff/{$uuid}", ['credentials' => null])
            ->assertOk();
        $this->assertNull(User::where('uuid', $uuid)->value('credentials'));
    }

    public function test_credentials_are_letters_not_markup(): void
    {
        $staff = User::factory()->bloodCenterStaff($this->facility)->create();

        foreach (['<b>RMT</b>', ', RMT', str_repeat('A', 41)] as $bad) {
            $this->actingAs($this->admin)
                ->patchJson("/api/blood-center/staff/{$staff->uuid}", ['credentials' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('credentials');
        }
    }

    public function test_staff_set_their_own_credentials_in_settings(): void
    {
        $staff = User::factory()->bloodCenterStaff($this->facility)->create();

        $this->actingAs($staff)
            ->patchJson('/api/blood-center/profile', ['credentials' => 'RN'])
            ->assertOk()
            ->assertJsonPath('profile.credentials', 'RN');
    }

    public function test_the_stock_report_signs_with_the_title_and_never_the_old_post(): void
    {
        // A post left over from before the merge (project owner, 2026-10-22) is not printed.
        $officer = User::factory()->bloodCenterStaff($this->facility, StaffRole::InventoryControlOfficer)->create([
            'first_name' => 'Rowena',
            'last_name' => 'Tan',
            'position' => 'Inventory Control Officer',
        ]);

        $this->actingAs($officer)->getJson('/api/blood-center/inventory/stock-report')
            ->assertOk()
            ->assertJsonPath('prepared_by', 'ROWENA TAN');

        $officer->update(['credentials' => 'RMT']);

        $this->actingAs($officer->fresh())->getJson('/api/blood-center/inventory/stock-report')
            ->assertOk()
            ->assertJsonPath('prepared_by', 'ROWENA TAN, RMT');
    }

    public function test_every_department_credits_the_name_with_its_credentials(): void
    {
        // Issuance's activity feed: the actor is loaded with a column list,
        // which must carry the credentials.
        $officer = User::factory()->bloodCenterStaff($this->facility, Department::Issuance)->create([
            'first_name' => 'Rowena',
            'last_name' => 'Tan',
            'credentials' => 'RMT',
        ]);

        $type = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $component = BloodComponent::factory()->create(['name' => 'Packed RBC']);
        $donor = DonorProfile::factory()->create(['donor_id' => User::factory()->create()->id, 'blood_type_id' => $type->id]);
        $donation = Donation::factory()->handedOver()->create(['facility_id' => $this->facility->id, 'donor_id' => $donor->donor_id]);
        DonationComponent::factory()->create(['donation_id' => $donation->id, 'component_id' => $component->id]);

        $this->actingAs($officer)->postJson('/api/blood-center/inventory', [
            'donation_id' => $donation->id,
            'units' => [[
                'component_id' => $component->id,
                'storage_location' => 'Cold Storage A-1',
                'expiry_date' => OperationalDay::today()->addDays(30)->toDateString(),
            ]],
        ])->assertCreated();

        $this->actingAs($officer)->getJson('/api/blood-center/inventory/activity')
            ->assertOk()
            ->assertJsonPath('data.0.actor', 'Rowena Tan, RMT');
    }
}
