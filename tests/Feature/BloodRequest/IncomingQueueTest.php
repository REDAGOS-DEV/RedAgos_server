<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BloodRequestStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\Department;
use App\Models\Billing;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * The fulfilling facility's incoming queue, and reviewing one request in it.
 *
 * Reviewing is read-only: a reviewer may open a request as often as they like
 * without holding a single bag. The availability figure shown beside a request
 * is advisory for the same reason a search result is, and the response says so.
 */
class IncomingQueueTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private Facility $centre;

    private Facility $hospital;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->centre, Department::Issuance)->create();
        $this->hospital = Facility::factory()->bloodBank()->approved()->create(['name' => 'St Luke Blood Bank']);

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed Red Blood Cells']);
        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_it_lists_only_requests_addressed_to_this_facility(): void
    {
        $this->incoming();

        $elsewhere = Facility::factory()->approved()->create();
        BloodRequest::factory()->raisedBy($this->hospital)->addressedTo($elsewhere)->create();

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.requesting_facility.name', 'St Luke Blood Bank');
    }

    public function test_emergencies_come_first_then_the_oldest(): void
    {
        $newerRoutine = $this->incoming(['request_date' => now()->subDay()]);
        $emergency = $this->incoming(['request_date' => now(), 'urgency_level' => 'emergency']);
        $oldestRoutine = $this->incoming(['request_date' => now()->subDays(4)]);

        $ids = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$emergency->id, $oldestRoutine->id, $newerRoutine->id], $ids);
    }

    public function test_the_queue_can_be_filtered_by_status_and_urgency(): void
    {
        $this->incoming();
        $this->incoming(['urgency_level' => 'emergency']);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?urgency_level=emergency')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_emergency', true);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?status=pending')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_queue_can_be_narrowed_to_requests_still_open(): void
    {
        $pending = $this->incoming();
        $this->incoming(['status' => 'fulfilled']);
        $this->incoming(['status' => 'rejected']);
        // Partly filled but closed by hand: finished, though not terminal.
        $this->incoming(['status' => 'partial', 'closed_at' => now()]);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?open=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_the_queue_can_be_split_by_whether_the_invoice_clears_release(): void
    {
        $paid = $this->incoming();
        Billing::factory()->create(['request_id' => $paid->id]);
        $owing = $this->incoming();
        Billing::factory()->chargeable()->create(['request_id' => $owing->id]);
        $notInvoiced = $this->incoming();

        $cleared = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?release_cleared=1')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$paid->id], $cleared);

        $stillOwing = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?release_cleared=0')
            ->assertOk()
            ->json('data.*.id');
        $this->assertEqualsCanonicalizing([$owing->id, $notInvoiced->id], $stillOwing);
    }

    public function test_the_search_finds_a_patient_or_the_hospital_in_any_case(): void
    {
        $forJuan = $this->incoming(['patient_surname' => 'Dela Cruz', 'patient_first_name' => 'Juan']);
        $other = Facility::factory()->bloodBank()->approved()->create(['name' => 'Davao Doctors Blood Bank']);
        $fromDoctors = BloodRequest::factory()->raisedBy($other)->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, 1)->create();

        $byPatient = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?search='.urlencode('juan dela'))
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$forJuan->id], $byPatient);

        $bySurname = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?search=CRUZ')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$forJuan->id], $bySurname);

        $byHospital = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?search='.urlencode('davao doctors'))
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$fromDoctors->id], $byHospital);

        // The reference still works.
        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?search='.urlencode(strtolower($forJuan->reference_number)))
            ->assertOk()
            ->assertJsonPath('data.0.id', $forJuan->id);
    }

    public function test_the_queue_can_be_narrowed_to_the_days_it_was_asked_for(): void
    {
        $old = $this->incoming(['request_date' => now()->subDays(10)]);
        $recent = $this->incoming(['request_date' => now()->subDays(2)]);

        $from = now()->subDays(5)->toDateString();
        $to = now()->toDateString();

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/blood-requests?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?to='.now()->subDays(8)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $old->id);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?from='.$to.'&to='.$from)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_requests_still_being_worked_come_before_finished_ones(): void
    {
        $finishedEmergency = $this->incoming(['status' => 'fulfilled', 'urgency_level' => 'emergency', 'request_date' => now()->subDays(9)]);
        $olderFinished = $this->incoming(['status' => 'rejected', 'request_date' => now()->subDays(12)]);
        $newerRoutine = $this->incoming(['request_date' => now()->subDay()]);
        $openEmergency = $this->incoming(['urgency_level' => 'emergency', 'request_date' => now()]);
        $olderRoutine = $this->incoming(['request_date' => now()->subDays(3)]);

        $ids = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests')
            ->assertOk()
            ->json('data.*.id');

        // Open: the emergency, then oldest first. Finished: newest first,
        // a finished emergency no longer jumping the queue.
        $this->assertSame(
            [$openEmergency->id, $olderRoutine->id, $newerRoutine->id, $finishedEmergency->id, $olderFinished->id],
            $ids
        );
    }

    public function test_the_queue_exports_as_csv_with_its_filters_and_is_audited(): void
    {
        $this->incoming(['patient_surname' => 'Santos', 'patient_first_name' => 'Maria', 'urgency_level' => 'emergency']);
        $this->incoming(['patient_surname' => 'Reyes', 'patient_first_name' => 'Pedro']);

        $response = $this->actingAs($this->staff)
            ->get('/api/blood-center/blood-requests/export?search=santos')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertStringContainsString('Reference,Date,Time,Hospital,Requester,Patient', $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('St Luke Blood Bank', $lines[1]);
        $this->assertStringContainsString('Emergency', $lines[1]);
        $this->assertStringNotContainsString('Reyes', $csv);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->staff->id,
            'action' => 'blood_requests.exported',
        ]);
    }

    public function test_the_export_needs_the_view_ability(): void
    {
        $collection = User::factory()->bloodCenterStaff($this->centre, Department::Collection)->create();

        $this->actingAs($collection)
            ->get('/api/blood-center/blood-requests/export')
            ->assertForbidden();
    }

    public function test_patient_requests_and_replenishment_orders_are_listed_and_counted_apart(): void
    {
        $forPatient = $this->incoming();
        $restock = BloodRequest::factory()->raisedBy($this->hospital)->addressedTo($this->centre)
            ->replenishment()->forStock($this->bloodType, $this->component, 3)->create();
        // Finished, so counted in neither tab's open figure.
        BloodRequest::factory()->raisedBy($this->hospital)->addressedTo($this->centre)
            ->replenishment()->forStock($this->bloodType, $this->component, 1)->create(['status' => 'fulfilled']);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?request_purpose=patient_transfusion')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $forPatient->id);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests?request_purpose=replenishment&open=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $restock->id);

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests/summary')
            ->assertOk()
            ->assertJsonPath('open_by_purpose.patient_transfusion', 1)
            ->assertJsonPath('open_by_purpose.replenishment', 1)
            // Each tab's quick filters count that tab alone.
            ->assertJsonPath('by_purpose.replenishment.totals.pending', 1)
            ->assertJsonPath('by_purpose.replenishment.totals.fulfilled', 1)
            ->assertJsonPath('by_purpose.patient_transfusion.totals.fulfilled', 0)
            ->assertJsonPath('by_purpose.patient_transfusion.totals.'.$forPatient->status->value, 1);
    }

    public function test_the_summary_counts_every_state_including_empty_ones(): void
    {
        $this->incoming();
        $this->incoming(['status' => BloodRequestStatus::Rejected, 'rejection_reason' => 'No stock.']);
        $this->incoming(['urgency_level' => 'emergency']);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests/summary')
            ->assertOk();

        $this->assertSame(2, $response->json('totals.pending'));
        $this->assertSame(1, $response->json('totals.rejected'));
        $this->assertSame(0, $response->json('totals.fulfilled'));
        $this->assertSame(1, $response->json('open_emergencies'));
    }

    public function test_the_summary_is_facility_isolated(): void
    {
        $elsewhere = Facility::factory()->approved()->create();
        BloodRequest::factory()->raisedBy($this->hospital)->addressedTo($elsewhere)->count(3)->create();

        $this->actingAs($this->staff)
            ->getJson('/api/blood-center/blood-requests/summary')
            ->assertOk()
            ->assertJsonPath('totals.pending', 0);
    }

    public function test_reviewing_shows_live_stock_beside_the_request(): void
    {
        $request = $this->incoming(['quantity' => 3]);
        $this->stock(5);

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/blood-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('inventory.lines.0.available', 5)
            // The line says what it asked for beside what can answer it; a
            // stock figure with nothing to judge it against is not a review.
            ->assertJsonPath('inventory.lines.0.requested', 3)
            ->assertJsonPath('inventory.lines.0.can_fully_cover', true)
            ->assertJsonPath('inventory.outstanding', 3)
            ->assertJsonPath('inventory.can_fully_cover', true)
            ->assertJsonPath('inventory.can_cover_now', 3)
            ->assertJsonPath('inventory.advisory', true);
    }

    public function test_reviewing_reports_a_shortfall_honestly(): void
    {
        $request = $this->incoming(['quantity' => 6]);
        $this->stock(2);

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/blood-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('inventory.lines.0.available', 2)
            ->assertJsonPath('inventory.lines.0.requested', 6)
            ->assertJsonPath('inventory.lines.0.can_fully_cover', false)
            ->assertJsonPath('inventory.can_fully_cover', false)
            ->assertJsonPath('inventory.can_cover_now', 2);
    }

    public function test_reviewing_holds_nothing(): void
    {
        $request = $this->incoming(['quantity' => 2]);
        $this->stock(4);

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/blood-requests/{$request->id}")
            ->assertOk();

        $this->assertDatabaseCount('request_allocations', 0);
        $this->assertSame(4, BloodUnit::query()->where('status', BloodUnitStatus::Available)->count());
    }

    public function test_another_facilitys_request_is_not_found(): void
    {
        $elsewhere = Facility::factory()->approved()->create();
        $theirs = BloodRequest::factory()->raisedBy($this->hospital)->addressedTo($elsewhere)->create();

        $this->actingAs($this->staff)
            ->getJson("/api/blood-center/blood-requests/{$theirs->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'request_not_found');
    }

    public function test_staff_without_the_view_ability_are_refused(): void
    {
        $collection = User::factory()->bloodCenterStaff($this->centre, Department::Collection)->create();

        $this->actingAs($collection)
            ->getJson('/api/blood-center/blood-requests')
            ->assertForbidden();
    }

    public function test_a_supervisor_watches_the_queue_but_decides_nothing(): void
    {
        $supervisor = User::factory()->bloodCenterSupervisor($this->centre)->create();
        $request = $this->incoming();

        $this->actingAs($supervisor)
            ->getJson('/api/blood-center/blood-requests')
            ->assertOk();

        $this->actingAs($supervisor)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/reject", ['reason' => 'No stock'])
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function incoming(array $overrides = []): BloodRequest
    {
        return BloodRequest::factory()
            ->raisedBy($this->hospital)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, $overrides['quantity'] ?? 2)
            // quantity is not a column any more, it is the line's. forStock
            // above has already applied it, so passing it on to create() would
            // try to write a column that no longer exists.
            ->create(Arr::except($overrides, 'quantity'));
    }

    private function stock(int $count): void
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
}
