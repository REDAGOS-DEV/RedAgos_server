<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\RequestSource;
use App\Enums\UrgencyLevel;
use App\Models\BloodRequest;
use App\Models\BloodRequestWalkIn;
use App\Models\Facility;
use App\Models\TransfusionRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * Not recording the same patient's need twice.
 *
 * A watcher may already have a request in: a Patient Transfusion Request the
 * hospital sent a share of to this centre, one with units nobody has been
 * asked for yet, or one simply in hand elsewhere. The lookup tells staff
 * which. A share already here is opened; unallocated units are added to the
 * same requirement ("continue"); recording a second requirement anyway needs
 * a reason.
 */
class WalkInDuplicateTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_a_share_the_hospital_already_sent_here_is_found_as_here(): void
    {
        $existing = $this->requirementFor('Dela Cruz', 'Juan', $this->centre);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', $this->lookup())
            ->assertOk()
            ->assertJsonPath('requires_acknowledgement', true)
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath('matches.0.id', $existing->id)
            ->assertJsonPath('matches.0.reference_number', $existing->reference_number)
            ->assertJsonPath('matches.0.relation', 'here')
            ->assertJsonPath('matches.0.allocation.id', $this->allocationAt($existing, $this->centre)->id);
    }

    public function test_names_match_regardless_of_case_and_spacing(): void
    {
        $this->requirementFor('DELA CRUZ', 'JUAN', $this->centre);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', $this->lookup([
                'patient_surname' => '  dela cruz ',
                'patient_first_name' => 'juan',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'matches');
    }

    public function test_the_reference_the_watcher_carries_is_matched_directly(): void
    {
        $existing = $this->requirementFor('Someone', 'Else', $this->otherCentre);
        $allocation = $this->allocationAt($existing, $this->otherCentre);

        foreach ([$existing->reference_number, $allocation->reference_number] as $reference) {
            $this->actingAs($this->issuance)
                ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', [
                    'hospital_id' => $this->hospital->id,
                    'presented_reference' => $reference,
                ])
                ->assertOk()
                ->assertJsonPath('matches.0.reference_number', $existing->reference_number)
                ->assertJsonPath('matches.0.relation', 'duplicate');
        }
    }

    public function test_units_another_centre_could_not_supply_are_offered_as_continue(): void
    {
        $existing = $this->scenarioTransfusion($this->otherCentre);
        $theirs = $this->allocationAt($existing, $this->otherCentre);
        $this->stock($this->otherCentre, $this->prbc, 2);
        $this->stock($this->otherCentre, $this->ffp, 1);
        $this->allocateAndRelease($theirs, $this->otherIssuance);

        // What the other centre has none of goes back to the requirement.
        foreach ([$this->ffp, $this->platelets] as $component) {
            $this->actingAs($this->otherIssuance)
                ->postJson("/api/blood-center/blood-requests/{$theirs->id}/items/{$this->lineFor($theirs, $component)}/close", [
                    'note' => 'None on the shelf.',
                ])
                ->assertOk();
        }

        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', $this->lookup())
            ->assertOk()
            ->assertJsonPath('matches.0.relation', 'continue')
            ->assertJsonPath('matches.0.facilities.0', 'Tagum Blood Center')
            ->assertJsonPath('matches.0.unallocated_quantity', 2);

        $lines = collect($response->json('matches.0.lines'))->keyBy('component.name');
        $this->assertSame(1, $lines['Fresh Frozen Plasma']['unallocated']);
        $this->assertSame(1, $lines['Platelet Concentrate']['unallocated']);
        $this->assertSame(0, $lines['Packed RBC']['unallocated']);
        $this->assertSame(2, $lines['Packed RBC']['fulfilled']);
    }

    public function test_a_requirement_another_centre_is_still_answering_is_a_duplicate(): void
    {
        $this->requirementFor('Dela Cruz', 'Juan', $this->otherCentre);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', $this->lookup())
            ->assertOk()
            ->assertJsonPath('matches.0.relation', 'duplicate');
    }

    public function test_finished_and_cancelled_requirements_are_not_duplicates(): void
    {
        foreach ([BloodRequestStatus::Fulfilled, BloodRequestStatus::Cancelled] as $status) {
            $this->requirementFor('Dela Cruz', 'Juan', $this->centre)->forceFill(['status' => $status])->save();
        }

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', $this->lookup())
            ->assertOk()
            ->assertJsonPath('requires_acknowledgement', false)
            ->assertJsonCount(0, 'matches');
    }

    public function test_an_old_requirement_with_the_same_name_is_not_a_duplicate(): void
    {
        $this->requirementFor('Dela Cruz', 'Juan', $this->centre)->forceFill(['request_date' => now()->subDays(30)])->save();

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', $this->lookup())
            ->assertOk()
            ->assertJsonCount(0, 'matches');
    }

    public function test_another_hospitals_patient_is_never_matched(): void
    {
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();

        TransfusionRequest::query()->create([
            'reference_number' => "PTR-{$otherHospital->id}-0001",
            'facility_id' => $otherHospital->id,
            'request_source' => RequestSource::BloodBankPortal,
            'patient_surname' => 'Dela Cruz',
            'patient_first_name' => 'Juan',
            'patient_age' => 54,
            'patient_sex' => 'male',
            'blood_type_id' => $this->bloodType->id,
            'urgency_level' => UrgencyLevel::Routine,
            'request_date' => now(),
        ]);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', $this->lookup())
            ->assertOk()
            ->assertJsonCount(0, 'matches');
    }

    public function test_recording_past_a_match_without_a_reason_is_refused(): void
    {
        $this->requirementFor('Dela Cruz', 'Juan', $this->otherCentre);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'possible_duplicate')
            ->assertJsonCount(1, 'matches');

        $this->assertSame(1, TransfusionRequest::query()->count());
        $this->assertSame(1, BloodRequest::query()->count());
    }

    public function test_recording_past_a_match_with_a_reason_keeps_the_reason(): void
    {
        $this->requirementFor('Dela Cruz', 'Juan', $this->otherCentre);

        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'duplicate_acknowledgement' => 'Hospital confirmed the other centre has none and asked us to supply.',
            ]))
            ->assertCreated()
            ->assertJsonPath('request.walk_in.duplicate_acknowledgement', 'Hospital confirmed the other centre has none and asked us to supply.');

        $this->assertNotNull(
            BloodRequestWalkIn::query()->where('request_id', $response->json('request.id'))->value('duplicate_acknowledgement')
        );
        $this->assertSame(2, TransfusionRequest::query()->count(), 'A separate need is a separate requirement.');
    }

    public function test_a_watcher_carrying_the_rest_to_another_centre_continues_the_same_requirement(): void
    {
        $requirement = $this->scenarioTransfusion($this->otherCentre);
        $theirs = $this->allocationAt($requirement, $this->otherCentre);
        $this->stock($this->otherCentre, $this->prbc, 2);
        $this->allocateAndRelease($theirs, $this->otherIssuance);

        foreach ([$this->ffp, $this->platelets] as $component) {
            $this->actingAs($this->otherIssuance)
                ->postJson("/api/blood-center/blood-requests/{$theirs->id}/items/{$this->lineFor($theirs, $component)}/close")
                ->assertOk();
        }

        $items = $requirement->items()->get()->keyBy('component_id');

        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->continuation($requirement, [
                $items[$this->ffp->id]->id => 2,
                $items[$this->platelets->id]->id => 1,
            ]))
            ->assertCreated()
            ->assertJsonPath('request.transfusion_request.reference_number', $requirement->reference_number)
            ->assertJsonPath('request.patient.full_name', 'DELA CRUZ, Juan')
            ->assertJsonPath('request.quantity', 3);

        $this->assertSame(1, TransfusionRequest::query()->count(), 'The same patient need, not a new one.');

        $mine = BloodRequest::query()->findOrFail($response->json('request.id'));
        $this->assertSame($requirement->id, $mine->transfusion_request_id);
        $this->assertSame(RequestSource::BloodCenterWalkIn, $mine->request_source);
        $this->assertNotNull($mine->walkIn);

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/transfusion-requests/{$requirement->id}")
            ->assertOk()
            ->assertJsonPath('request.allocation_count', 2)
            ->assertJsonPath('request.totals.unallocated', 0)
            ->assertJsonPath('request.totals.awaiting', 3)
            ->assertJsonPath('request.needs_allocation', false);

        $this->assertDatabaseHas('blood_request_events', [
            'transfusion_request_id' => $requirement->id,
            'request_id' => null,
            'event' => 'allocations_added',
        ]);
    }

    public function test_a_continuation_cannot_ask_for_more_than_is_unallocated(): void
    {
        $requirement = $this->scenarioTransfusion($this->otherCentre);
        $items = $requirement->items()->get()->keyBy('component_id');

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->continuation($requirement, [
                $items[$this->prbc->id]->id => 1,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->assertSame(1, BloodRequest::query()->count());
    }

    public function test_a_continuation_must_name_the_same_hospitals_requirement(): void
    {
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $theirs = TransfusionRequest::query()->create([
            'reference_number' => "PTR-{$otherHospital->id}-0001",
            'facility_id' => $otherHospital->id,
            'patient_surname' => 'Dela Cruz',
            'patient_first_name' => 'Juan',
            'blood_type_id' => $this->bloodType->id,
            'urgency_level' => UrgencyLevel::Routine,
            'request_date' => now(),
        ]);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->continuation($theirs, [1 => 1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfusion_request_id']);
    }

    public function test_the_lookup_needs_a_name_or_a_reference(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', ['hospital_id' => $this->hospital->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_surname', 'patient_first_name']);
    }

    public function test_the_hospital_is_warned_of_an_open_walk_in_before_recording_another(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertCreated();

        $this->actingAs($this->requester)
            ->postJson('/api/hospital/transfusion-requests/patient-matches', [
                'patient_surname' => 'Dela Cruz',
                'patient_first_name' => 'Juan',
            ])
            ->assertOk()
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath('matches.0.request_source', 'blood_center_walk_in')
            ->assertJsonPath('matches.0.facilities.0', 'Davao Blood Center');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function lookup(array $overrides = []): array
    {
        return [
            'hospital_id' => $this->hospital->id,
            'patient_surname' => 'Dela Cruz',
            'patient_first_name' => 'Juan',
            'blood_type_id' => $this->bloodType->id,
            ...$overrides,
        ];
    }

    /**
     * A walk-in adding this centre's share to an existing requirement.
     *
     * Patient, blood type, component and indication all come from the
     * requirement, so the payload names only which requirement lines it
     * answers and how many units of each.
     *
     * @param  array<int, int>  $quantities  requirement line id => quantity
     * @return array<string, mixed>
     */
    private function continuation(TransfusionRequest $requirement, array $quantities): array
    {
        $payload = $this->walkInPayload();

        foreach (['patient_surname', 'patient_first_name', 'patient_middle_name', 'patient_age', 'patient_sex', 'blood_type_id'] as $field) {
            $payload[$field] = null;
        }

        $payload['transfusion_request_id'] = $requirement->id;
        $payload['items'] = array_map(
            fn (int $itemId, int $quantity): array => ['transfusion_request_item_id' => $itemId, 'quantity' => $quantity],
            array_keys($quantities),
            array_values($quantities)
        );

        return $payload;
    }

    /**
     * A requirement for two packed cells, asked in full of one centre.
     */
    private function requirementFor(string $surname, string $firstName, Facility $centre): TransfusionRequest
    {
        return $this->recordTransfusion(
            [[$this->prbc, 2]],
            [[$centre, [[$this->prbc, 2]]]],
            ['patient_surname' => $surname, 'patient_first_name' => $firstName],
        );
    }
}
