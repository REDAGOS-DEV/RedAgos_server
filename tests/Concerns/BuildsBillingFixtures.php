<?php

namespace Tests\Concerns;

use App\Enums\BloodUnitStatus;
use App\Enums\Department;
use App\Enums\StaffRole;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * A blood centre, a hospital, priced stock and requests held against it.
 *
 * Shared by the billing tests that need a statement with money on it. Every
 * statement is zero by default, as the system ships; a test that needs money
 * prices the component at the centre first, which is what turning the
 * subsidy off would do in production.
 */
trait BuildsBillingFixtures
{
    protected Facility $centre;

    protected Facility $hospital;

    protected User $requester;

    protected User $inventoryStaff;

    protected User $billingClerk;

    protected User $billingSupervisor;

    protected BloodType $bloodType;

    protected BloodComponent $component;

    protected DonorProfile $donorProfile;

    protected function setUpBillingFixtures(): void
    {
        $this->centre = Facility::factory()->approved()->create();
        $this->inventoryStaff = User::factory()->bloodCenterStaff($this->centre, Department::Issuance)->create();
        $this->billingClerk = User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create();
        $this->billingSupervisor = User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingSupervisor)->create();

        $this->hospital = Facility::factory()->bloodBank()->approved()->create();
        $this->requester = User::factory()->bloodBankStaff($this->hospital)->create();

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed Red Blood Cells', 'price' => 0]);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    /**
     * Price the component at the centre that fulfils these requests.
     */
    protected function priceComponent(float|string $amount): void
    {
        FacilityBloodComponent::updateOrCreate(
            ['facility_id' => $this->centre->id, 'component_id' => $this->component->id],
            ['price' => $amount]
        );
    }

    /**
     * Put available units of the component on the centre's shelf.
     */
    protected function stock(int $count): void
    {
        $donation = Donation::factory()->create([
            'facility_id' => $this->centre->id,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        BloodUnit::factory()->count($count)->create([
            'facility_id' => $this->centre->id,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            'status' => BloodUnitStatus::Available,
        ]);
    }

    /**
     * A request with units reserved against it, which raises its statement.
     *
     * A Patient Transfusion by default, whose watcher owes the bill; a
     * replenishment when $replenishment is set, which is billed by statement only.
     */
    protected function allocatedRequest(int $units, ?int $askedFor = null, bool $replenishment = false): BloodRequest
    {
        $factory = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, $askedFor ?? $units);

        $request = ($replenishment ? $factory->replenishment() : $factory)->create();

        $this->stock($units);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['quantity' => $units])
            ->assertOk();

        return $request->fresh();
    }

    /**
     * Reserve more units against a request that is still open.
     */
    protected function topUp(BloodRequest $request, int $units): void
    {
        $this->stock($units);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();
    }

    /**
     * Record a cash payment as the billing clerk.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function payCash(BloodRequest $request, float|int|string $amount, array $extra = []): TestResponse
    {
        return $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/payments", [
                'amount_paid' => $amount,
                'payment_method' => 'cash',
                ...$extra,
            ]);
    }
}
