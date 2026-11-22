<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\FacilityStatus;
use App\Enums\RequestSource;
use App\Enums\StaffRole;
use App\Models\BloodRequest;
use App\Models\TransfusionRequest;
use App\Models\User;
use App\Notifications\TransfusionShareTakenUp;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * Every hospital's Blood Requests, read by any blood centre (project owner, 2026-11-19).
 *
 * A centre reads every Blood Request, whoever holds its shares, as a stub:
 * the patient's name, blood type, components, status and which centre holds
 * each share. It may add its own share of units nobody was asked for yet.
 */
class NetworkTransfusionRequestTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_a_centre_reads_a_blood_request_it_holds_no_share_of(): void
    {
        $requirement = $this->partlyAsked();
        $theirs = $this->allocationAt($requirement, $this->otherCentre);

        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/transfusion-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference_number', $requirement->reference_number)
            ->assertJsonPath('data.0.hospital.name', 'Southern Hospital Blood Bank')
            ->assertJsonPath('data.0.patient.full_name', $requirement->patientFullName())
            ->assertJsonPath('data.0.blood_type.code', 'O+')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.lines.0.required', 5)
            ->assertJsonPath('data.0.lines.0.unallocated', 2)
            ->assertJsonPath('data.0.shares.0.reference_number', $theirs->reference_number)
            ->assertJsonPath('data.0.shares.0.centre', 'Tagum Blood Center')
            ->assertJsonPath('data.0.shares.0.quantity', 3)
            ->assertJsonPath('data.0.shares.0.is_mine', false)
            // Another centre's share is named, never opened from here.
            ->assertJsonPath('data.0.shares.0.id', null)
            ->assertJsonPath('data.0.unallocated_quantity', 2)
            ->assertJsonPath('data.0.can_add_share', true);
    }

    public function test_only_the_stub_leaves_the_api(): void
    {
        $this->partlyAsked();

        $row = $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/transfusion-requests')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(['full_name'], array_keys($row['patient']));

        foreach (['requester_name', 'recorder_name', 'walk_in', 'allocations', 'billing', 'items'] as $private) {
            $this->assertArrayNotHasKey($private, $row);
        }

        foreach (['units', 'allocations', 'walk_in', 'billing'] as $private) {
            $this->assertArrayNotHasKey($private, $row['shares'][0]);
        }
    }

    public function test_the_search_finds_it_by_either_reference_the_patient_or_the_hospital(): void
    {
        $requirement = $this->partlyAsked();
        $theirs = $this->allocationAt($requirement, $this->otherCentre);
        $this->recordTransfusion([[$this->prbc, 1]], [[$this->otherCentre, [[$this->prbc, 1]]]], [
            'patient_surname' => 'Reyes',
            'patient_first_name' => 'Ana',
        ]);

        foreach ([$requirement->reference_number, strtolower($theirs->reference_number), 'dela cruz', 'Juan Dela', 'southern'] as $term) {
            $response = $this->actingAs($this->issuance)
                ->getJson('/api/blood-center/transfusion-requests?search='.urlencode($term))
                ->assertOk();

            $this->assertContains($requirement->reference_number, collect($response->json('data'))->pluck('reference_number')->all(), $term);
        }

        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/transfusion-requests?search=reyes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient.full_name', fn (string $name): bool => stripos($name, 'reyes') !== false);
    }

    public function test_finished_requests_are_listed_only_when_asked_for_all(): void
    {
        $requirement = $this->partlyAsked();
        $requirement->forceFill(['status' => BloodRequestStatus::Cancelled, 'closed_at' => now()])->save();

        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/transfusion-requests')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/transfusion-requests?scope=all')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_open', false)
            ->assertJsonPath('data.0.can_add_share', false);
    }

    public function test_a_centre_adds_its_share_of_the_unallocated_units(): void
    {
        Notification::fake();
        $requirement = $this->partlyAsked();

        $response = $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/transfusion-requests/{$requirement->id}/share", $this->share($requirement, 2))
            ->assertCreated()
            ->assertJsonPath('request.unallocated_quantity', 0)
            ->assertJsonPath('request.can_add_share', false)
            ->assertJsonPath('request.shares.1.centre', 'Davao Blood Center')
            ->assertJsonPath('request.shares.1.is_mine', true);

        $share = BloodRequest::query()->findOrFail($response->json('share.id'));

        $this->assertSame($this->centre->id, (int) $share->target_facility_id);
        $this->assertSame($this->hospital->id, (int) $share->facility_id);
        $this->assertSame($requirement->id, (int) $share->transfusion_request_id);
        $this->assertSame(RequestSource::BloodBankPortal, $share->request_source);
        $this->assertSame(BloodRequestStatus::Pending, $share->status);
        $this->assertSame(2, $share->quantity);
        // Nobody at the hospital asked: the centre's staff member is named.
        $this->assertNull($share->requested_by);
        $this->assertSame($this->issuance->id, (int) $share->recorded_by);
        $response->assertJsonPath('request.shares.1.id', $share->id);

        $this->assertDatabaseHas('blood_request_events', [
            'transfusion_request_id' => $requirement->id,
            'event' => 'allocations_added',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'request.share_added']);

        Notification::assertSentTo($this->requester, TransfusionShareTakenUp::class);

        // It is worked from this centre's Incoming Requests, like any other share.
        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/blood-requests')
            ->assertOk()
            ->assertJsonPath('data.0.reference_number', $share->reference_number);
    }

    public function test_no_more_than_is_unallocated_may_be_taken(): void
    {
        $requirement = $this->partlyAsked();

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/transfusion-requests/{$requirement->id}/share", $this->share($requirement, 3))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');

        $this->assertSame(0, BloodRequest::query()->where('target_facility_id', $this->centre->id)->count());
    }

    public function test_nothing_may_be_taken_when_every_unit_is_asked_for(): void
    {
        $requirement = $this->recordTransfusion([[$this->prbc, 2]], [[$this->otherCentre, [[$this->prbc, 2]]]]);

        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/transfusion-requests')
            ->assertJsonPath('data.0.can_add_share', false);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/transfusion-requests/{$requirement->id}/share", $this->share($requirement, 1))
            ->assertUnprocessable();
    }

    public function test_a_closed_blood_request_cannot_be_added_to(): void
    {
        $requirement = $this->partlyAsked();
        $requirement->forceFill(['status' => BloodRequestStatus::Cancelled, 'closed_at' => now()])->save();

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/transfusion-requests/{$requirement->id}/share", $this->share($requirement, 1))
            ->assertConflict()
            ->assertJsonPath('code', 'request_closed');
    }

    public function test_a_hospital_not_on_redagos_is_sent_to_the_walk_in(): void
    {
        $requirement = $this->partlyAsked();
        $this->hospital->forceFill(['status' => FacilityStatus::Unregistered])->save();

        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/transfusion-requests')
            ->assertJsonPath('data.0.hospital.is_registered', false)
            ->assertJsonPath('data.0.can_add_share', false)
            ->assertJsonPath('data.0.add_share_blocked', "Not on RedAgos: record the watcher's walk-in instead.");

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/transfusion-requests/{$requirement->id}/share", $this->share($requirement, 1))
            ->assertConflict()
            ->assertJsonPath('code', 'hospital_not_registered');
    }

    public function test_a_centre_still_reviewing_its_share_answers_that_first(): void
    {
        $requirement = $this->recordTransfusion(
            [[$this->prbc, 5]],
            [[$this->centre, [[$this->prbc, 2]]]],
        );

        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/transfusion-requests')
            ->assertJsonPath('data.0.can_add_share', false)
            ->assertJsonPath('data.0.shares.0.is_mine', true)
            ->assertJsonPath('data.0.shares.0.id', $this->allocationAt($requirement, $this->centre)->id);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/transfusion-requests/{$requirement->id}/share", $this->share($requirement, 1))
            ->assertUnprocessable();
    }

    public function test_reading_needs_requests_view_and_adding_needs_requests_record(): void
    {
        $requirement = $this->partlyAsked();
        $billing = User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create();
        $phlebotomist = User::factory()->bloodCenterStaff($this->centre, StaffRole::Phlebotomist)->create();

        $this->actingAs($phlebotomist)->getJson('/api/blood-center/transfusion-requests')->assertForbidden();

        $this->actingAs($billing)->getJson('/api/blood-center/transfusion-requests')->assertOk();
        $this->actingAs($billing)
            ->postJson("/api/blood-center/transfusion-requests/{$requirement->id}/share", $this->share($requirement, 1))
            ->assertForbidden();

        $this->actingAs($this->requester)->getJson('/api/blood-center/transfusion-requests')->assertForbidden();
    }

    public function test_incoming_requests_finds_a_share_by_its_blood_request_reference(): void
    {
        $requirement = $this->recordTransfusion([[$this->prbc, 2]], [[$this->centre, [[$this->prbc, 2]]]]);
        $share = $this->allocationAt($requirement, $this->centre);

        $this->actingAs($this->issuance)
            ->getJson('/api/blood-center/blood-requests?search='.urlencode($requirement->reference_number))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference_number', $share->reference_number);
    }

    /**
     * Packed cells for five units, three of them asked of the other centre: two unallocated.
     */
    private function partlyAsked(): TransfusionRequest
    {
        return $this->recordTransfusion(
            [[$this->prbc, 5]],
            [[$this->otherCentre, [[$this->prbc, 3]]]],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function share(TransfusionRequest $requirement, int $quantity): array
    {
        return ['lines' => [[
            'transfusion_request_item_id' => (int) $requirement->items()->where('component_id', $this->prbc->id)->value('id'),
            'quantity' => $quantity,
        ]]];
    }
}
