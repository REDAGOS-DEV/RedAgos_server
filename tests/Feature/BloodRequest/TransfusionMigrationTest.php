<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\TransfusionRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * Moving the requests made before Patient Transfusion Requests existed under one.
 *
 * Before, a patient's need was one blood request, and whatever its centre
 * could not supply travelled on as a follow-up chained to it. Each chain now
 * becomes one requirement with an allocation per request in it. The follow-up
 * columns are gone by the time these tests run, so each test puts them back
 * — as the migration's down() would — and drives the grouping directly.
 */
class TransfusionMigrationTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();

        Schema::table('blood_requests', function (Blueprint $table): void {
            $table->foreignId('parent_request_id')->nullable()->constrained('blood_requests');
        });

        Schema::table('blood_request_items', function (Blueprint $table): void {
            $table->foreignId('parent_item_id')->nullable()->constrained('blood_request_items');
        });

        $this->migration = require database_path('migrations/2026_09_30_100004_move_patient_transfusions_under_parents.php');
    }

    public function test_a_lone_patient_request_becomes_a_requirement_with_one_allocation(): void
    {
        $request = $this->scenarioRequest();
        DB::table('blood_request_events')->insert($this->legacyEvent($request->id, 'submitted'));

        $this->migration->fold();

        $requirement = TransfusionRequest::query()->sole();
        $request->refresh();

        $this->assertSame("PTR-{$this->hospital->id}-0001", $requirement->reference_number);
        $this->assertSame($requirement->id, $request->transfusion_request_id);
        $this->assertSame($request->patient_surname, $requirement->patient_surname);
        $this->assertSame(BloodRequestStatus::Pending, $requirement->status);
        $this->assertSame(
            [[$this->prbc->id, 2], [$this->ffp->id, 2], [$this->platelets->id, 1]],
            $requirement->items()->orderBy('id')->get()->map(fn ($item): array => [$item->component_id, $item->quantity])->all()
        );

        foreach ($request->items as $item) {
            $this->assertSame(
                $item->component_id,
                $requirement->items()->whereKey($item->transfusion_request_item_id)->value('component_id')
            );
        }

        $this->assertDatabaseHas('blood_request_events', [
            'request_id' => $request->id,
            'transfusion_request_id' => $requirement->id,
        ]);
    }

    public function test_a_follow_up_chain_folds_into_one_requirement(): void
    {
        $root = $this->scenarioRequest();
        $child = $this->followUpOf($root, $this->otherCentre, [[$this->ffp, 1], [$this->platelets, 1]]);
        $grandchild = $this->followUpOf($child, $this->centre, [[$this->platelets, 1]]);

        DB::table('blood_request_events')->insert([
            $this->legacyEvent($child->id, 'follow_up_created'),
            $this->legacyEvent($root->id, 'remainder_forwarded'),
            $this->legacyEvent($grandchild->id, 'follow_up_withdrawn'),
        ]);

        $this->migration->fold();

        $requirement = TransfusionRequest::query()->sole();

        $this->assertSame(
            [$requirement->id, $requirement->id, $requirement->id],
            [$root->fresh()->transfusion_request_id, $child->fresh()->transfusion_request_id, $grandchild->fresh()->transfusion_request_id]
        );
        $this->assertSame(3, $requirement->items()->count(), 'The requirement is what the first request asked for.');
        $this->assertSame(5, (int) $requirement->items()->sum('quantity'));

        $platelets = $requirement->items()->where('component_id', $this->platelets->id)->value('id');
        $this->assertSame($platelets, $grandchild->items()->value('transfusion_request_item_id'), 'Mapped back through the chain.');

        $this->assertSame(
            ['submitted', 'allocations_added', 'allocation_withdrawn'],
            DB::table('blood_request_events')->orderBy('id')->pluck('event')->all()
        );
    }

    public function test_a_follow_up_line_the_first_request_never_had_adds_to_the_requirement(): void
    {
        $root = $this->requestFor([[$this->prbc, 2]]);
        $this->followUpOf($root, $this->otherCentre, [[$this->ffp, 1]], linkLines: false);

        $this->migration->fold();

        $requirement = TransfusionRequest::query()->sole();
        $this->assertSame(1, (int) $requirement->items()->where('component_id', $this->ffp->id)->value('quantity'));
    }

    public function test_replenishment_requests_are_left_as_they_are(): void
    {
        $restock = $this->requestFor([[$this->prbc, 4]], ['request_purpose' => 'replenishment', 'patient_surname' => null, 'patient_first_name' => null]);

        $this->migration->fold();

        $this->assertSame(0, TransfusionRequest::query()->count());
        $this->assertNull($restock->fresh()->transfusion_request_id);
    }

    public function test_a_chain_every_centre_refused_becomes_a_cancelled_requirement(): void
    {
        $root = $this->scenarioRequest();
        $root->forceFill(['status' => BloodRequestStatus::Rejected])->save();

        $withdrawn = $this->requestFor([[$this->prbc, 1]], ['patient_surname' => 'Reyes']);
        $withdrawn->forceFill(['status' => BloodRequestStatus::Cancelled])->save();

        $this->migration->fold();

        $refused = TransfusionRequest::query()->where('patient_surname', $root->patient_surname)->sole();
        $this->assertSame(BloodRequestStatus::Cancelled, $refused->status);
        $this->assertSame('Declined by every facility it was sent to.', $refused->cancellation_reason);

        $cancelled = TransfusionRequest::query()->where('patient_surname', 'Reyes')->sole();
        $this->assertSame('Withdrawn by the hospital.', $cancelled->cancellation_reason);
    }

    public function test_folding_twice_changes_nothing(): void
    {
        $this->scenarioRequest();

        $this->migration->fold();
        $this->migration->fold();

        $this->assertSame(1, TransfusionRequest::query()->count());
    }

    public function test_resettling_after_the_fold_gives_each_requirement_its_status(): void
    {
        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 2);
        $this->stock($this->centre, $this->platelets, 1);
        $this->allocateAndRelease($request);

        $this->migration->fold();

        $requirement = TransfusionRequest::query()->sole();
        $this->assertSame(BloodRequestStatus::Pending, $requirement->status);

        $this->artisan('requests:resettle', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(BloodRequestStatus::Pending, $requirement->fresh()->status, 'A dry run saves nothing.');

        $this->artisan('requests:resettle')->assertSuccessful();

        $requirement->refresh();
        $this->assertSame(BloodRequestStatus::Fulfilled, $requirement->status);
        $this->assertNotNull($requirement->closed_at);
    }

    /**
     * @param  array<int, array{0: BloodComponent, 1: int}>  $components
     * @param  array<string, mixed>  $state
     */
    private function requestFor(array $components, array $state = []): BloodRequest
    {
        return BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->state(['blood_type_id' => $this->bloodType->id, ...$state])
            ->withComponents($components)
            ->create();
    }

    /**
     * A follow-up as the retired FollowUpRequestService wrote one.
     *
     * @param  array<int, array{0: BloodComponent, 1: int}>  $components
     */
    private function followUpOf(BloodRequest $parent, $centre, array $components, bool $linkLines = true): BloodRequest
    {
        $child = BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($centre)
            ->state([
                'blood_type_id' => $parent->blood_type_id,
                'patient_surname' => $parent->patient_surname,
                'patient_first_name' => $parent->patient_first_name,
            ])
            ->withComponents($components)
            ->create();

        DB::table('blood_requests')->where('id', $child->id)->update(['parent_request_id' => $parent->id]);

        if ($linkLines) {
            foreach ($child->items as $item) {
                DB::table('blood_request_items')->where('id', $item->id)->update([
                    'parent_item_id' => $parent->items()->where('component_id', $item->component_id)->value('id'),
                ]);
            }
        }

        return $child;
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyEvent(int $requestId, string $event): array
    {
        return [
            'request_id' => $requestId,
            'event' => $event,
            'to_status' => 'pending',
            'lines' => '[]',
            'created_at' => now(),
        ];
    }
}
