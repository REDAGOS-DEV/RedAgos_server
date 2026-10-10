<?php

namespace Tests\Feature\BloodRequest\Concerns;

use App\Enums\BloodUnitStatus;
use App\Enums\Department;
use App\Enums\IndicationCode;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\TransfusionRequest;
use App\Models\User;

/**
 * The world the walk-in, partial-fulfilment and Patient Transfusion tests share.
 *
 * Two blood centres, so a remainder has somewhere else to go; one hospital
 * blood bank, which is the institutional party behind every request; and the
 * three components of the scenario the workflow was specified against —
 * packed cells, plasma and platelets.
 */
trait BuildsFulfilmentScenarios
{
    protected Facility $centre;

    protected Facility $otherCentre;

    protected Facility $hospital;

    protected User $issuance;

    protected User $otherIssuance;

    protected User $requester;

    protected BloodType $bloodType;

    protected BloodComponent $prbc;

    protected BloodComponent $ffp;

    protected BloodComponent $platelets;

    protected DonorProfile $donorProfile;

    protected function buildScenario(): void
    {
        $this->centre = Facility::factory()->approved()->create(['name' => 'Davao Blood Center']);
        $this->otherCentre = Facility::factory()->approved()->create(['name' => 'Tagum Blood Center']);
        $this->hospital = Facility::factory()->bloodBank()->approved()->create(['name' => 'Southern Hospital Blood Bank']);

        $this->issuance = User::factory()->bloodCenterStaff($this->centre, Department::Issuance)->create();
        $this->otherIssuance = User::factory()->bloodCenterStaff($this->otherCentre, Department::Issuance)->create();
        $this->requester = User::factory()->bloodBankStaff($this->hospital)->create();

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->prbc = BloodComponent::factory()->create(['name' => 'Packed RBC', 'price' => 0]);
        $this->ffp = BloodComponent::factory()->create(['name' => 'Fresh Frozen Plasma', 'price' => 0]);
        $this->platelets = BloodComponent::factory()->create(['name' => 'Platelet Concentrate', 'price' => 0]);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    /**
     * Put issuable units of one component on a centre's shelf.
     *
     * They expire in thirty days unless told otherwise — the factory default —
     * and are of the scenario's blood type unless another is given.
     */
    protected function stock(Facility $facility, BloodComponent $component, int $count, ?int $expiresInDays = null, ?BloodType $bloodType = null): void
    {
        if ($count < 1) {
            return;
        }

        $donation = Donation::factory()->create([
            'facility_id' => $facility->id,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        BloodUnit::factory()->count($count)->create([
            'facility_id' => $facility->id,
            'blood_type_id' => ($bloodType ?? $this->bloodType)->id,
            'component_id' => $component->id,
            'donation_id' => $donation->id,
            'status' => BloodUnitStatus::Available,
            ...($expiresInDays !== null ? ['expiry_date' => now()->addDays($expiresInDays)->toDateString()] : []),
        ]);
    }

    /**
     * The scenario's request: PRBC 2, FFP 2, platelets 1, raised through the portal.
     */
    protected function scenarioRequest(?Facility $centre = null): BloodRequest
    {
        return BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($centre ?? $this->centre)
            ->state(['blood_type_id' => $this->bloodType->id])
            ->withComponents([
                [$this->prbc, 2],
                [$this->ffp, 2],
                [$this->platelets, 1],
            ])
            ->create();
    }

    /**
     * Record a Patient Transfusion Request through the hospital portal, split as given.
     *
     * Lines are [component, required]; shares are [centre, [[component,
     * quantity], ...]]. Goes through the real endpoint, so every test starts
     * from a requirement written exactly as the hospital would write it.
     *
     * @param  array<int, array{0: BloodComponent, 1: int}>  $lines
     * @param  array<int, array{0: Facility, 1: array<int, array{0: BloodComponent, 1: int}>}>  $shares
     * @param  array<string, mixed>  $overrides
     */
    protected function recordTransfusion(array $lines, array $shares, array $overrides = []): TransfusionRequest
    {
        $id = $this->actingAs($this->requester)
            ->postJson('/api/hospital/transfusion-requests', $this->transfusionPayload($lines, $shares, $overrides))
            ->assertCreated()
            ->json('request.id');

        return TransfusionRequest::query()->findOrFail($id);
    }

    /**
     * The payload recordTransfusion() sends, for a test that wants the response itself.
     *
     * @param  array<int, array{0: BloodComponent, 1: int}>  $lines
     * @param  array<int, array{0: Facility, 1: array<int, array{0: BloodComponent, 1: int}>}>  $shares
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function transfusionPayload(array $lines, array $shares, array $overrides = []): array
    {
        $indications = [
            $this->prbc->id => IndicationCode::R1->value,
            $this->ffp->id => IndicationCode::F1->value,
            $this->platelets->id => IndicationCode::P1->value,
        ];

        return [
            'internal_stock_confirmed' => true,
            'blood_type_id' => $this->bloodType->id,
            'urgency_level' => 'emergency',
            'patient_surname' => 'Dela Cruz',
            'patient_first_name' => 'Juan',
            'patient_age' => 54,
            'patient_sex' => 'male',
            'lines' => array_map(fn (array $line): array => [
                'component_id' => $line[0]->id,
                'quantity' => $line[1],
                'indication_code' => $indications[$line[0]->id] ?? null,
            ], $lines),
            'allocations' => array_map(fn (array $share): array => [
                'facility_id' => $share[0]->id,
                'lines' => array_map(fn (array $line): array => [
                    'component_id' => $line[0]->id,
                    'quantity' => $line[1],
                ], $share[1]),
            ], $shares),
            ...$overrides,
        ];
    }

    /**
     * The scenario's requirement — PRBC 2, FFP 2, platelets 1 — asked in full of one centre.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function scenarioTransfusion(?Facility $centre = null, array $overrides = []): TransfusionRequest
    {
        $lines = [[$this->prbc, 2], [$this->ffp, 2], [$this->platelets, 1]];

        return $this->recordTransfusion($lines, [[$centre ?? $this->centre, $lines]], $overrides);
    }

    /**
     * A requirement's allocation at one centre — the newest, if it was asked more than once.
     */
    protected function allocationAt(TransfusionRequest $requirement, Facility $centre): BloodRequest
    {
        return BloodRequest::query()
            ->where('transfusion_request_id', $requirement->id)
            ->where('target_facility_id', $centre->id)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * A complete walk-in payload the hospital has confirmed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function walkInPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'hospital_id' => $this->hospital->id,
            'urgency_level' => 'emergency',
            'patient_surname' => 'Dela Cruz',
            'patient_first_name' => 'Juan',
            'patient_middle_name' => 'Santos',
            'patient_age' => 54,
            'patient_sex' => 'male',
            'blood_type_id' => $this->bloodType->id,
            'presented_reference' => null,
            'attending_physician' => 'Dr. Maria Reyes',
            'patient_ward' => 'Surgical Ward 3',
            'items' => [
                ['component_id' => $this->prbc->id, 'quantity' => 2, 'indication_code' => IndicationCode::R1->value],
                ['component_id' => $this->ffp->id, 'quantity' => 2, 'indication_code' => IndicationCode::F1->value],
                ['component_id' => $this->platelets->id, 'quantity' => 1, 'indication_code' => IndicationCode::P1->value],
            ],
            'representative' => [
                'name' => 'Pedro Dela Cruz',
                'relationship' => 'Son',
                'contact' => '09171234567',
                'id_type' => 'philsys',
                'id_number' => '1234-5678-9012',
            ],
            'verification' => [
                'confirmed' => true,
                'verifier_name' => 'Ana Lim',
                'verifier_position' => 'Blood Bank Medical Technologist',
                'verifier_contact' => '(082) 222-1234',
                'verified_at' => now()->subMinutes(5)->toIso8601String(),
                'notes' => 'Confirmed the patient is admitted and the physician signed the request.',
            ],
        ], $overrides);
    }

    /**
     * Reserve and release everything a centre can for a request.
     */
    protected function allocateAndRelease(BloodRequest $request, ?User $staff = null): void
    {
        $staff ??= $this->issuance;

        $this->actingAs($staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate")
            ->assertOk();

        $this->actingAs($staff)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release")
            ->assertOk();
    }

    /**
     * Find a request's line for one component.
     */
    protected function lineFor(BloodRequest $request, BloodComponent $component): int
    {
        return (int) $request->items()->where('component_id', $component->id)->value('id');
    }
}
