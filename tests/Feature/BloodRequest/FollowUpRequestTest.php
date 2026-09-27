<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\RequestSource;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\BloodRequestSubmitted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * Sourcing what one centre could not supply from another.
 *
 * The rule under test is that a remainder is only ever asked of one facility
 * at a time: whatever a follow-up carries is taken off what the original still
 * needs, and comes back to it if the follow-up is refused or withdrawn.
 */
class FollowUpRequestTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_the_hospital_sources_the_remainder_from_another_centre(): void
    {
        $parent = $this->partFilledScenario();

        $response = $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/follow-up", [
                'target_facility_id' => $this->otherCentre->id,
                'items' => [
                    ['parent_item_id' => $this->lineFor($parent, $this->ffp), 'quantity' => 1],
                    ['parent_item_id' => $this->lineFor($parent, $this->platelets), 'quantity' => 1],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('request.status', BloodRequestStatus::Pending->value)
            ->assertJsonPath('request.target_facility.id', $this->otherCentre->id)
            ->assertJsonPath('request.parent.reference_number', $parent->reference_number)
            ->assertJsonPath('request.quantity', 2)
            ->assertJsonPath('request.patient.surname', $parent->patient_surname)
            ->assertJsonPath('request.request_source', RequestSource::BloodBankPortal->value);

        $child = BloodRequest::query()->findOrFail($response->json('request.id'));
        $this->assertSame($parent->id, $child->parent_request_id);
        $this->assertSame(
            [$this->lineFor($parent, $this->ffp), $this->lineFor($parent, $this->platelets)],
            $child->items()->orderBy('id')->pluck('parent_item_id')->all()
        );

        // Every parent line is now resolved: supplied here or forwarded.
        $parent = $parent->fresh();
        $this->assertSame(BloodRequestStatus::Partial, $parent->status);
        $this->assertNotNull($parent->closed_at);

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/{$parent->id}")
            ->assertJsonPath('request.follow_ups.0.reference_number', $child->reference_number)
            ->assertJsonPath('request.forwarded_quantity', 2)
            ->assertJsonPath('request.is_open', false);
    }

    public function test_the_other_centre_is_notified_of_the_follow_up(): void
    {
        $parent = $this->partFilledScenario();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/follow-up", $this->followUpPayload($parent))
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->otherIssuance->id,
            'type' => BloodRequestSubmitted::class,
        ]);
    }

    public function test_a_follow_up_cannot_ask_for_more_than_is_left(): void
    {
        $parent = $this->partFilledScenario();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/follow-up", [
                'target_facility_id' => $this->otherCentre->id,
                'items' => [['parent_item_id' => $this->lineFor($parent, $this->ffp), 'quantity' => 2]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');

        $this->assertSame(1, BloodRequest::query()->count());
    }

    public function test_a_supplied_line_cannot_be_forwarded(): void
    {
        $parent = $this->partFilledScenario();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/follow-up", [
                'target_facility_id' => $this->otherCentre->id,
                'items' => [['parent_item_id' => $this->lineFor($parent, $this->prbc), 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');
    }

    public function test_the_follow_up_must_go_to_a_different_centre(): void
    {
        $parent = $this->partFilledScenario();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/follow-up", [
                ...$this->followUpPayload($parent),
                'target_facility_id' => $this->centre->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_facility_id');
    }

    public function test_forwarded_quantity_is_no_longer_allocatable_at_the_original_centre(): void
    {
        $parent = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->allocateAndRelease($parent);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/follow-up", [
                'target_facility_id' => $this->otherCentre->id,
                'items' => [['parent_item_id' => $this->lineFor($parent, $this->ffp), 'quantity' => 1]],
            ])
            ->assertCreated();

        $this->stock($this->centre, $this->ffp, 3);

        // FFP asked for 2, 1 forwarded: only 1 may be held here.
        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$parent->id}/allocate")
            ->assertOk()
            ->assertJsonPath('held_total', 3)
            ->assertJsonPath('short_by', 1);

        $this->assertSame(
            1,
            $parent->allocations()->claiming()->where('request_item_id', $this->lineFor($parent, $this->ffp))->count()
        );
    }

    public function test_a_rejected_follow_up_returns_its_quantity_to_the_original(): void
    {
        $parent = $this->partFilledScenario();
        $child = $this->followUpOf($parent);

        $this->actingAs($this->otherIssuance)
            ->postJson("/api/blood-center/blood-requests/{$child->id}/reject", ['reason' => 'No stock here either.'])
            ->assertOk();

        $parent = $parent->fresh();
        $this->assertSame(BloodRequestStatus::Partial, $parent->status);
        $this->assertNull($parent->closed_at, 'The remainder is outstanding at the original centre again.');
        $this->assertSame(1, $parent->events()->where('event', 'follow_up_withdrawn')->count());

        $this->stock($this->centre, $this->ffp, 1);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$parent->id}/allocate")
            ->assertOk()
            ->assertJsonPath('held_total', 4);
    }

    public function test_a_withdrawn_follow_up_returns_its_quantity_to_the_original(): void
    {
        $parent = $this->partFilledScenario();
        $child = $this->followUpOf($parent);

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$child->id}/cancel")
            ->assertOk();

        $this->assertNull($parent->fresh()->closed_at);

        $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/{$parent->id}")
            ->assertJsonPath('request.forwarded_quantity', 0)
            ->assertJsonPath('request.forwardable_quantity', 2);
    }

    public function test_nothing_can_be_forwarded_twice(): void
    {
        $parent = $this->partFilledScenario();
        $this->followUpOf($parent);

        $third = Facility::factory()->approved()->create();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/follow-up", [
                'target_facility_id' => $third->id,
                'items' => [['parent_item_id' => $this->lineFor($parent, $this->ffp), 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');
    }

    public function test_a_whole_untouched_request_cannot_be_moved_as_a_follow_up(): void
    {
        $parent = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->platelets, 1)
            ->create();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/follow-up", [
                'target_facility_id' => $this->otherCentre->id,
                'items' => [['parent_item_id' => $this->lineFor($parent, $this->platelets), 'quantity' => 1]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'forward_would_empty');
    }

    public function test_a_line_the_hospital_no_longer_needs_cannot_be_forwarded(): void
    {
        $parent = $this->partFilledScenario();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/items/{$this->lineFor($parent, $this->platelets)}/close")
            ->assertOk();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/follow-up", [
                'target_facility_id' => $this->otherCentre->id,
                'items' => [['parent_item_id' => $this->lineFor($parent, $this->platelets), 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');
    }

    public function test_a_line_the_centre_could_not_supply_can_still_be_forwarded(): void
    {
        $parent = $this->partFilledScenario();

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$parent->id}/items/{$this->lineFor($parent, $this->platelets)}/close")
            ->assertOk();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/follow-up", [
                'target_facility_id' => $this->otherCentre->id,
                'items' => [['parent_item_id' => $this->lineFor($parent, $this->platelets), 'quantity' => 1]],
            ])
            ->assertCreated();
    }

    public function test_a_refused_request_cannot_be_followed_up(): void
    {
        $parent = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->platelets, 1)
            ->rejected()
            ->create();

        $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/follow-up", [
                'target_facility_id' => $this->otherCentre->id,
                'items' => [['parent_item_id' => $this->lineFor($parent, $this->platelets), 'quantity' => 1]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'parent_not_forwardable');
    }

    public function test_another_hospital_cannot_follow_up_this_hospitals_request(): void
    {
        $parent = $this->partFilledScenario();
        $stranger = User::factory()->bloodBankStaff(Facility::factory()->bloodBank()->approved()->create())->create();

        $this->actingAs($stranger)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/follow-up", $this->followUpPayload($parent))
            ->assertNotFound();
    }

    public function test_a_watcher_bringing_the_remainder_to_another_centre_becomes_a_walk_in_follow_up(): void
    {
        $parent = $this->partFilledScenario();

        $payload = $this->walkInPayload(['parent_request_id' => $parent->id]);
        unset($payload['patient_surname'], $payload['patient_first_name'], $payload['patient_age'], $payload['patient_sex'], $payload['blood_type_id']);
        $payload['items'] = [
            ['parent_item_id' => $this->lineFor($parent, $this->ffp), 'quantity' => 1],
            ['parent_item_id' => $this->lineFor($parent, $this->platelets), 'quantity' => 1],
        ];

        $response = $this->actingAs($this->otherIssuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $payload)
            ->assertCreated()
            ->assertJsonPath('request.request_source', RequestSource::BloodCenterWalkIn->value)
            ->assertJsonPath('request.target_facility.id', $this->otherCentre->id)
            ->assertJsonPath('request.parent.reference_number', $parent->reference_number)
            ->assertJsonPath('request.patient.surname', $parent->patient_surname)
            ->assertJsonPath('request.blood_type.id', $parent->blood_type_id)
            ->assertJsonPath('request.quantity', 2);

        $child = BloodRequest::query()->findOrFail($response->json('request.id'));
        $this->assertSame(
            $this->ffp->id,
            BloodRequestItem::query()->where('request_id', $child->id)->orderBy('id')->value('component_id'),
            'The component comes from the parent line, not from input.'
        );
        $this->assertNotNull($parent->fresh()->closed_at);
    }

    public function test_a_walk_in_follow_up_must_continue_the_same_hospitals_request(): void
    {
        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $foreign = BloodRequest::factory()
            ->raisedBy($otherHospital)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->ffp, 2)
            ->create();

        $payload = $this->walkInPayload(['parent_request_id' => $foreign->id]);
        $payload['items'] = [['parent_item_id' => $this->lineFor($foreign, $this->ffp), 'quantity' => 1]];

        $this->actingAs($this->otherIssuance)
            ->postJson('/api/blood-center/blood-requests/walk-in', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_request_id');
    }

    /**
     * The scenario request at the first centre, filled 2 / 1 / 0.
     */
    private function partFilledScenario(): BloodRequest
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);
        $this->allocateAndRelease($request);

        return $request->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function followUpPayload(BloodRequest $parent): array
    {
        return [
            'target_facility_id' => $this->otherCentre->id,
            'items' => [
                ['parent_item_id' => $this->lineFor($parent, $this->ffp), 'quantity' => 1],
                ['parent_item_id' => $this->lineFor($parent, $this->platelets), 'quantity' => 1],
            ],
        ];
    }

    private function followUpOf(BloodRequest $parent): BloodRequest
    {
        $response = $this->actingAs($this->requester)
            ->postJson("/api/hospital/blood-requests/{$parent->id}/follow-up", $this->followUpPayload($parent))
            ->assertCreated();

        return BloodRequest::query()->findOrFail($response->json('request.id'));
    }
}
