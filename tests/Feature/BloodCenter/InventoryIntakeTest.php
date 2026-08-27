<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonationComponent;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InventoryIntakeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private int $facilityId;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    private Donation $donation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->bloodCenterStaff()->create();
        $this->facilityId = $this->staff->facility_id;

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC']);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);

        $this->donation = Donation::factory()->create([
            'facility_id' => $this->facilityId,
            'donor_id' => $this->donorProfile->donor_id,
            'status' => 'completed',
        ]);

        // The laboratory's declaration of what this donation was separated
        // into. Intake is constrained to it, so without this row there is
        // nothing inventory is allowed to book in. Quantity is generous
        // because several of these tests record more than one unit.
        DonationComponent::factory()->quantity(10)->create([
            'donation_id' => $this->donation->id,
            'component_id' => $this->component->id,
            'declared_by' => $this->staff->id,
        ]);

    }

    public function test_a_completed_donation_yields_units_carrying_the_donors_blood_type(): void
    {
        $response = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $this->payload())
            ->assertCreated();

        $this->assertCount(1, $response->json('units'));
        $this->assertSame('O+', $response->json('units.0.blood_type.code'));
        $this->assertSame(BloodUnitStatus::Available->value, $response->json('units.0.status'));
    }

    public function test_blood_type_is_derived_and_never_taken_from_the_client(): void
    {
        $wrongType = BloodType::firstOrCreate(['code' => 'AB-'], ['label' => 'AB-']);

        $payload = $this->payload();
        $payload['units'][0]['blood_type_id'] = $wrongType->id;

        $response = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $payload)
            ->assertCreated();

        // It is the one field on a unit that can kill someone if it is wrong.
        $this->assertSame('O+', $response->json('units.0.blood_type.code'));
    }

    public function test_a_generated_id_follows_the_scheme(): void
    {
        $response = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $this->payload())
            ->assertCreated();

        $this->assertSame(
            "RA{$this->facilityId}-{$this->donation->id}-01",
            $response->json('units.0.id')
        );
    }

    public function test_a_second_intake_continues_the_sequence(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $this->payload())
            ->assertCreated();

        $response = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $this->payload())
            ->assertCreated();

        // Not -01 again: the sequence is derived from what the donation already
        // has, under the donation lock.
        $this->assertSame(
            "RA{$this->facilityId}-{$this->donation->id}-02",
            $response->json('units.0.id')
        );
    }

    public function test_the_sequence_is_not_derived_lexicographically(): void
    {
        BloodUnit::factory()->create([
            'id' => "RA{$this->facilityId}-{$this->donation->id}-99",
            'facility_id' => $this->facilityId,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $this->donation->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $this->payload())
            ->assertCreated();

        // A lexicographic MAX(id) would read '-99' as the highest forever.
        $this->assertSame(
            "RA{$this->facilityId}-{$this->donation->id}-100",
            $response->json('units.0.id')
        );
    }

    public function test_a_supplied_unit_id_is_honoured(): void
    {
        $payload = $this->payload();
        $payload['units'][0]['unit_id'] = 'BAG-778812';

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $payload)
            ->assertCreated()
            ->assertJsonPath('units.0.id', 'BAG-778812');
    }

    public function test_a_duplicate_supplied_unit_id_is_refused(): void
    {
        $payload = $this->payload();
        $payload['units'][0]['unit_id'] = 'BAG-778812';

        $this->actingAs($this->staff)->postJson('/api/blood-center/inventory', $payload)->assertCreated();

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('units.0.unit_id');
    }

    public function test_several_units_may_be_recorded_from_one_donation(): void
    {
        $payload = $this->payload();
        $payload['units'][] = ['component_id' => $this->component->id, 'expiry_date' => $this->inDays(20)];

        $response = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $payload)
            ->assertCreated();

        $this->assertSame(
            ["RA{$this->facilityId}-{$this->donation->id}-01", "RA{$this->facilityId}-{$this->donation->id}-02"],
            array_column($response->json('units'), 'id')
        );
    }

    public function test_more_than_ten_units_is_refused(): void
    {
        $payload = $this->payload();

        for ($i = 0; $i < 10; $i++) {
            $payload['units'][] = ['component_id' => $this->component->id, 'expiry_date' => $this->inDays(20)];
        }

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('units');
    }

    public function test_another_facilitys_donation_is_not_found(): void
    {
        $otherFacility = Facility::factory()->approved()->create();

        $foreign = Donation::factory()->create([
            'facility_id' => $otherFacility->id,
            'donor_id' => $this->donorProfile->donor_id,
            'status' => 'completed',
        ]);

        $payload = $this->payload();
        $payload['donation_id'] = $foreign->id;

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $payload)
            ->assertNotFound()
            ->assertJsonPath('code', 'donation_not_found');
    }

    public function test_a_donation_that_is_not_completed_is_refused(): void
    {
        // `tested` is the status immediately before `completed`, so this is the
        // near-miss the gate exists to catch, not an obviously wrong one.
        $this->donation->update(['status' => 'tested']);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_not_completed');
    }

    public function test_a_donor_without_a_blood_type_is_refused(): void
    {
        $this->donorProfile->update(['blood_type_id' => null]);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'donor_blood_type_missing');
    }

    public function test_an_expiry_date_in_the_past_is_refused(): void
    {
        $payload = $this->payload();
        $payload['units'][0]['expiry_date'] = $this->inDays(-1);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('units.0.expiry_date');
    }

    public function test_an_expiry_date_of_today_is_accepted(): void
    {
        $payload = $this->payload();
        $payload['units'][0]['expiry_date'] = $this->inDays(0);

        // The other half of D8: a bag stamped today is usable for the rest of
        // today, and the sweep only expires expiry_date < today.
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $payload)
            ->assertCreated()
            ->assertJsonPath('units.0.days_remaining', 0);
    }

    public function test_intake_writes_an_audit_row_per_unit(): void
    {
        $response = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/inventory', $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory.recorded',
            'actor_id' => $this->staff->id,
            'auditable_id' => $response->json('units.0.id'),
        ]);

        $context = AuditLog::query()->where('action', 'inventory.recorded')->first()->context;

        $this->assertSame($this->facilityId, $context['facility_id']);
        $this->assertSame($this->donation->id, $context['donation_id']);
    }

    public function test_a_donor_is_refused(): void
    {
        // The role gate is what is under test, so this deliberately skips
        // ->donor(), which would build a profile and another random blood type —
        // and collide with this class's pinned O+ about one time in eight.
        $this->actingAs(User::factory()->withRole(RoleName::Donor)->create())
            ->postJson('/api/blood-center/inventory', $this->payload())
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'donation_id' => $this->donation->id,
            'units' => [
                [
                    'component_id' => $this->component->id,
                    'storage_location' => 'Cold Storage A-1',
                    'expiry_date' => $this->inDays(30),
                ],
            ],
        ];
    }

    private function inDays(int $days): string
    {
        return OperationalDay::today()->addDays($days)->toDateString();
    }
}
