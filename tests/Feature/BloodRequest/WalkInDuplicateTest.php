<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Models\BloodRequest;
use App\Models\BloodRequestWalkIn;
use App\Models\Facility;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * Not recording the same patient's need twice.
 *
 * A watcher may already have a request in: sent by the hospital blood bank to
 * this centre, part-filled by another centre, or simply raised elsewhere. The
 * lookup tells staff which, and recording a second request anyway needs a
 * reason — or, for a remainder, a link back to the request it continues.
 */
class WalkInDuplicateTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_a_request_the_hospital_already_sent_here_is_found_as_here(): void
    {
        $existing = $this->portalRequestFor('Dela Cruz', 'Juan', $this->centre);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', $this->lookup())
            ->assertOk()
            ->assertJsonPath('requires_acknowledgement', true)
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath('matches.0.id', $existing->id)
            ->assertJsonPath('matches.0.relation', 'here');
    }

    public function test_names_match_regardless_of_case_and_spacing(): void
    {
        $this->portalRequestFor('DELA CRUZ', 'JUAN', $this->centre);

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
        $existing = $this->portalRequestFor('Someone', 'Else', $this->otherCentre);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', [
                'hospital_id' => $this->hospital->id,
                'presented_reference' => $existing->reference_number,
            ])
            ->assertOk()
            ->assertJsonPath('matches.0.reference_number', $existing->reference_number)
            ->assertJsonPath('matches.0.relation', 'duplicate');
    }

    public function test_a_part_filled_request_at_another_centre_is_offered_as_a_follow_up(): void
    {
        $existing = $this->scenarioRequest($this->otherCentre);
        $this->stock($this->otherCentre, $this->prbc, 2);
        $this->stock($this->otherCentre, $this->ffp, 1);
        $this->allocateAndRelease($existing, $this->otherIssuance);

        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', $this->lookup([
                'patient_surname' => $existing->patient_surname,
                'patient_first_name' => $existing->patient_first_name,
            ]))
            ->assertOk()
            ->assertJsonPath('matches.0.relation', 'follow_up')
            ->assertJsonPath('matches.0.facility.name', 'Tagum Blood Center')
            ->assertJsonPath('matches.0.forwardable_quantity', 2);

        $lines = collect($response->json('matches.0.lines'))->keyBy('component.name');
        $this->assertSame(1, $lines['Fresh Frozen Plasma']['forwardable']);
        $this->assertSame(1, $lines['Platelet Concentrate']['forwardable']);
        $this->assertSame(0, $lines['Packed RBC']['forwardable']);
    }

    public function test_an_untouched_request_at_another_centre_is_a_duplicate(): void
    {
        $this->portalRequestFor('Dela Cruz', 'Juan', $this->otherCentre);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', $this->lookup())
            ->assertOk()
            ->assertJsonPath('matches.0.relation', 'duplicate');
    }

    public function test_finished_refused_and_withdrawn_requests_are_not_duplicates(): void
    {
        foreach ([BloodRequestStatus::Fulfilled, BloodRequestStatus::Rejected, BloodRequestStatus::Cancelled] as $status) {
            $this->portalRequestFor('Dela Cruz', 'Juan', $this->centre)->update(['status' => $status]);
        }

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', $this->lookup())
            ->assertOk()
            ->assertJsonPath('requires_acknowledgement', false)
            ->assertJsonCount(0, 'matches');
    }

    public function test_an_old_request_with_the_same_name_is_not_a_duplicate(): void
    {
        $this->portalRequestFor('Dela Cruz', 'Juan', $this->centre)->update(['request_date' => now()->subDays(30)]);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', $this->lookup())
            ->assertOk()
            ->assertJsonCount(0, 'matches');
    }

    public function test_another_hospitals_patient_is_never_matched(): void
    {
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();

        BloodRequest::factory()
            ->raisedBy($otherHospital)
            ->addressedTo($this->centre)
            ->state([
                'blood_type_id' => $this->bloodType->id,
                'patient_surname' => 'Dela Cruz',
                'patient_first_name' => 'Juan',
            ])
            ->create();

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', $this->lookup())
            ->assertOk()
            ->assertJsonCount(0, 'matches');
    }

    public function test_recording_past_a_match_without_a_reason_is_refused(): void
    {
        $this->portalRequestFor('Dela Cruz', 'Juan', $this->otherCentre);

        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'possible_duplicate')
            ->assertJsonCount(1, 'matches');

        $this->assertSame(1, BloodRequest::query()->count());
    }

    public function test_recording_past_a_match_with_a_reason_keeps_the_reason(): void
    {
        $this->portalRequestFor('Dela Cruz', 'Juan', $this->otherCentre);

        $response = $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload([
                'duplicate_acknowledgement' => 'Hospital confirmed the other centre has none and asked us to supply.',
            ]))
            ->assertCreated()
            ->assertJsonPath('request.walk_in.duplicate_acknowledgement', 'Hospital confirmed the other centre has none and asked us to supply.');

        $this->assertNotNull(
            BloodRequestWalkIn::query()->where('request_id', $response->json('request.id'))->value('duplicate_acknowledgement')
        );
    }

    public function test_the_lookup_needs_a_name_or_a_reference(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in/duplicates', ['hospital_id' => $this->hospital->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_surname', 'patient_first_name']);
    }

    public function test_the_hospital_is_warned_of_an_open_walk_in_before_raising_another(): void
    {
        $this->actingAs($this->issuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $this->walkInPayload())
            ->assertCreated();

        $this->actingAs($this->requester)
            ->postJson('/api/hospital/blood-requests/patient-matches', [
                'patient_surname' => 'Dela Cruz',
                'patient_first_name' => 'Juan',
            ])
            ->assertOk()
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath('matches.0.request_source', 'blood_center_walk_in')
            ->assertJsonPath('matches.0.facility.name', 'Davao Blood Center');
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

    private function portalRequestFor(string $surname, string $firstName, Facility $centre): BloodRequest
    {
        return BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($centre)
            ->forStock($this->bloodType, $this->prbc, 2)
            ->state([
                'patient_surname' => $surname,
                'patient_first_name' => $firstName,
            ])
            ->create();
    }
}
