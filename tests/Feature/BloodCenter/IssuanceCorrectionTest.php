<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AllocationStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\CorrectionSubject;
use App\Enums\Department;
use App\Enums\StaffRole;
use App\Http\Requests\CorrectDispatchRequest;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodRequestEvent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\CorrectionRequest;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\RequestAllocation;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Redirector;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * Corrections to what Issuance keeps: a unit's details, and a dispatch record.
 *
 * The Issuance head is the Inventory Control Officer. The IT Data Entry Clerk
 * files unit corrections (and cannot edit a unit directly); the Dispatch
 * Coordinator files dispatch corrections. Who may file is decided by the
 * service, so a custom role in the same department — which inherits the
 * department's abilities — is still refused.
 */
class IssuanceCorrectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $centre;

    private Facility $hospital;

    private User $head;

    private User $clerk;

    private User $dispatcher;

    private User $centerAdmin;

    private User $requester;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Facility::factory()->approved()->create();
        $this->hospital = Facility::factory()->bloodBank()->approved()->create();

        $this->head = User::factory()->bloodCenterStaff($this->centre, StaffRole::InventoryControlOfficer)->create();
        $this->clerk = User::factory()->bloodCenterStaff($this->centre, StaffRole::ItDataClerk)->create();
        $this->dispatcher = User::factory()->bloodCenterStaff($this->centre, StaffRole::DispatchCoordinator)->create();
        $this->centerAdmin = User::factory()->bloodCenterSupervisor($this->centre)->create();
        $this->requester = User::factory()->bloodBankStaff($this->hospital)->create();

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC', 'price' => 0]);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    // --- Unit details -------------------------------------------------------------

    public function test_the_clerk_files_a_unit_correction_and_the_head_applies_it(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01', 'storage_location' => 'Cold Storage A-1']);

        $id = $this->fileForUnit($this->clerk, $unit, ['storage_location' => 'Freezer B'])
            ->assertCreated()
            ->assertJsonPath('data.subject', 'unit_details')
            ->assertJsonPath('data.target_label', 'Unit AVAIL-01')
            ->assertJsonPath('data.approver_label', StaffRole::InventoryControlOfficer->label())
            ->json('data.id');

        $this->assertDatabaseHas('correction_requests', [
            'id' => $id,
            'blood_unit_id' => 'AVAIL-01',
            'donation_id' => null,
            'status' => 'pending',
        ]);

        // Nothing moves until it is decided.
        $this->assertSame('Cold Storage A-1', $unit->refresh()->storage_location);

        $this->actingAs($this->head)
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertOk();

        $this->assertSame('Freezer B', $unit->refresh()->storage_location);

        foreach (['correction.requested', 'correction.approved', 'correction.applied'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }

        // The write runs as the requester, through the ordinary update path.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory.updated',
            'actor_id' => $this->clerk->id,
            'auditable_id' => 'AVAIL-01',
        ]);
    }

    public function test_a_unit_correction_never_moves_a_quarantined_unit_out_of_quarantine(): void
    {
        $unit = $this->makeUnit(['id' => 'QUAR-01'], ['quarantined']);

        $id = $this->fileForUnit($this->clerk, $unit, ['expiry_date' => $this->inDays(40)])->assertCreated()->json('data.id');

        $this->actingAs($this->head)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $unit->refresh();
        $this->assertSame(BloodUnitStatus::Quarantined, $unit->status);
        $this->assertSame($this->inDays(40), $unit->expiry_date->toDateString());
    }

    public function test_the_clerk_cannot_edit_a_unit_directly(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);

        $this->actingAs($this->clerk)
            ->patchJson('/api/blood-center/inventory/'.$unit->id, ['storage_location' => 'Freezer B'])
            ->assertForbidden();
    }

    public function test_the_filing_policy_runs_before_the_record_is_looked_up(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);
        $custom = User::factory()->bloodCenterCustomStaff($this->centre, Department::Issuance, 'Stock Auditor')->create();

        // A custom role inherits Issuance's abilities, so it clears the route
        // gate — and is still refused, whether the unit exists or not, with
        // nothing written.
        foreach ([$custom, $this->dispatcher, $this->head] as $user) {
            $this->actingAs($user)
                ->postJson('/api/blood-center/inventory/AVAIL-01/corrections', ['reason' => 'Wrong shelf', 'changes' => ['storage_location' => 'Freezer B']])
                ->assertForbidden()
                ->assertJsonPath('code', 'not_your_record');

            $this->actingAs($user)
                ->postJson('/api/blood-center/inventory/NO-SUCH-UNIT/corrections', ['reason' => 'Wrong shelf', 'changes' => ['storage_location' => 'Freezer B']])
                ->assertForbidden()
                ->assertJsonPath('code', 'not_your_record');
        }

        $this->assertDatabaseCount('correction_requests', 0);
        $this->assertNotContains('inventory.audit', $custom->abilities());
        $this->assertSame('Cold Storage A-1', $unit->refresh()->storage_location);
    }

    public function test_other_predefined_roles_may_not_file_a_unit_correction(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);

        foreach ([StaffRole::ComponentTechnologist, StaffRole::BillingClerk, StaffRole::LabSupervisor] as $role) {
            $this->fileForUnit(User::factory()->bloodCenterStaff($this->centre, $role)->create(), $unit, ['storage_location' => 'Freezer B'])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('correction_requests', 0);
    }

    public function test_a_supervisor_may_file_a_unit_correction(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);

        $this->fileForUnit($this->centerAdmin, $unit, ['storage_location' => 'Freezer B'])->assertCreated();
    }

    public function test_an_expired_units_date_can_be_corrected_but_not_its_shelf(): void
    {
        $unit = $this->makeUnit(['id' => 'EXP-01'], ['expired']);

        $this->fileForUnit($this->clerk, $unit, ['storage_location' => 'Freezer B', 'expiry_date' => $this->inDays(30)])
            ->assertStatus(409)
            ->assertJsonPath('code', 'unit_not_editable');

        $id = $this->fileForUnit($this->clerk, $unit, ['expiry_date' => $this->inDays(30)])->assertCreated()->json('data.id');

        $this->actingAs($this->head)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $this->assertSame(BloodUnitStatus::Available, $unit->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.reinstated', 'auditable_id' => 'EXP-01']);
    }

    public function test_a_reserved_or_issued_unit_cannot_be_corrected(): void
    {
        foreach ([BloodUnitStatus::Reserved, BloodUnitStatus::Issued] as $status) {
            $unit = $this->makeUnit(['id' => 'HELD-'.$status->value, 'status' => $status]);

            $this->fileForUnit($this->clerk, $unit, ['storage_location' => 'Freezer B'])
                ->assertStatus(409)
                ->assertJsonPath('code', 'unit_not_editable');
        }
    }

    public function test_a_past_expiry_is_a_validation_error_on_the_change(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);

        $this->fileForUnit($this->clerk, $unit, ['expiry_date' => $this->inDays(-1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('changes.expiry_date');
    }

    public function test_a_direct_edit_after_filing_stops_the_correction_overwriting_it(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01', 'expiry_date' => $this->inDays(5)]);

        $id = $this->fileForUnit($this->clerk, $unit, ['expiry_date' => $this->inDays(40)])->assertCreated()->json('data.id');

        // The head edits the very field directly while the request waits.
        $this->actingAs($this->head)
            ->patchJson('/api/blood-center/inventory/AVAIL-01', ['expiry_date' => $this->inDays(50)])
            ->assertOk();

        $this->actingAs($this->head)
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'record_changed');

        $this->assertSame($this->inDays(50), $unit->refresh()->expiry_date->toDateString());
        $this->assertSame('pending', CorrectionRequest::findOrFail($id)->status);
    }

    public function test_an_edit_to_another_field_does_not_block_the_correction(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01', 'expiry_date' => $this->inDays(5), 'storage_location' => 'Cold Storage A-1']);

        $id = $this->fileForUnit($this->clerk, $unit, ['expiry_date' => $this->inDays(40)])->assertCreated()->json('data.id');

        $this->actingAs($this->head)
            ->patchJson('/api/blood-center/inventory/AVAIL-01', ['storage_location' => 'Freezer B'])
            ->assertOk();

        $this->actingAs($this->head)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $unit->refresh();
        $this->assertSame($this->inDays(40), $unit->expiry_date->toDateString());
        $this->assertSame('Freezer B', $unit->storage_location);
    }

    public function test_another_facilitys_unit_is_not_found(): void
    {
        $unit = $this->makeUnit(['id' => 'THEIRS-01'], [], Facility::factory()->approved()->create()->id);

        $this->fileForUnit($this->clerk, $unit, ['storage_location' => 'Freezer B'])
            ->assertNotFound()
            ->assertJsonPath('code', 'unit_not_found');
    }

    public function test_only_one_correction_per_unit_may_wait_at_a_time(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);

        $this->fileForUnit($this->clerk, $unit, ['storage_location' => 'Freezer B'])->assertCreated();

        $this->fileForUnit($this->clerk, $unit, ['storage_location' => 'Freezer C'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'correction_pending');
    }

    // --- Dispatch record ------------------------------------------------------------

    public function test_the_coordinator_files_a_dispatch_correction_and_the_head_applies_it(): void
    {
        [$allocation, $unit] = $this->dispatched(['handed_to' => 'Pedro Cruz']);
        $correctedAt = now()->subMinutes(45)->startOfSecond();

        $id = $this->fileForAllocation($this->dispatcher, $allocation, [
            'released_at' => $correctedAt->toIso8601String(),
            'handed_to' => 'Maria Santos',
        ])->assertCreated()->assertJsonPath('data.subject', 'dispatch')->json('data.id');

        $this->assertDatabaseHas('correction_requests', ['id' => $id, 'request_allocation_id' => $allocation->id, 'donation_id' => null]);

        $this->actingAs($this->head)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $allocation->refresh();
        $this->assertSame('Maria Santos', $allocation->handed_to);
        $this->assertSame($correctedAt->getTimestamp(), $allocation->released_at->getTimestamp());

        // Nothing about the workflow moved: still dispatched, not received.
        $this->assertSame(AllocationStatus::Released, $allocation->status);
        $this->assertNull($allocation->received_at);
        $this->assertSame(BloodUnitStatus::Issued, $unit->refresh()->status);

        $event = BloodRequestEvent::query()->where('event', 'dispatch_corrected')->firstOrFail();
        $this->assertSame($allocation->request_id, $event->request_id);

        // Field names only: the name of whoever took the units is not audited.
        $audit = AuditLog::query()->where('action', 'allocation.dispatch_corrected')->firstOrFail();
        $this->assertEqualsCanonicalizing(['released_at', 'handed_to'], $audit->context['fields']);
        $this->assertStringNotContainsString('Maria', json_encode(AuditLog::query()->get()->map->context->all()));
    }

    public function test_a_release_time_in_another_timezone_lands_on_the_right_instant(): void
    {
        [$allocation] = $this->dispatched();
        $instant = now()->subMinutes(20)->startOfSecond();

        $id = $this->fileForAllocation($this->dispatcher, $allocation, [
            'released_at' => $instant->setTimezone('Asia/Manila')->format('Y-m-d\TH:i:sP'),
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->head)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $this->assertSame($instant->getTimestamp(), $allocation->refresh()->released_at->getTimestamp());
    }

    public function test_an_unreleased_hold_has_no_dispatch_record_to_correct(): void
    {
        $request = $this->requestForStock();
        $unit = $this->makeUnit(['status' => BloodUnitStatus::Reserved]);
        $hold = RequestAllocation::factory()->holding($request, $unit)->create(['allocated_by' => $this->head->id]);

        $this->fileForAllocation($this->dispatcher, $hold, ['handed_to' => 'Maria Santos'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'nothing_to_correct');
    }

    public function test_a_release_time_must_sit_between_reserved_and_received(): void
    {
        [$allocation] = $this->dispatched();
        $allocation->update(['allocated_at' => now()->subHours(2), 'released_at' => now()->subHour(), 'received_at' => now()->subMinutes(10)]);

        foreach ([
            'before it was reserved' => now()->subHours(3),
            'after it was received' => now()->subMinutes(5),
            'in the future' => now()->addHour(),
        ] as $case => $at) {
            $this->fileForAllocation($this->dispatcher, $allocation, ['released_at' => $at->toIso8601String()])
                ->assertStatus(422, "A release time {$case} must be refused.")
                ->assertJsonValidationErrors('changes.released_at');
        }
    }

    public function test_a_dispatch_correction_cannot_be_validated_without_its_allocation(): void
    {
        $form = CorrectDispatchRequest::create('/', 'POST', ['handed_to' => 'Maria Santos']);
        $this->prepare($form);

        $this->expectException(ValidationException::class);

        $form->validateResolved();
    }

    public function test_the_coordinator_may_not_correct_a_unit_nor_a_custom_role_a_dispatch(): void
    {
        [$allocation] = $this->dispatched();
        $custom = User::factory()->bloodCenterCustomStaff($this->centre, Department::Issuance, 'Stock Auditor')->create();

        $this->fileForAllocation($custom, $allocation, ['handed_to' => 'Maria Santos'])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_your_record');

        $this->actingAs($custom)
            ->postJson('/api/blood-center/allocations/999999/corrections', ['reason' => 'x', 'changes' => ['handed_to' => 'y']])
            ->assertForbidden();
    }

    public function test_the_heads_own_dispatch_correction_goes_to_the_center_admin(): void
    {
        [$allocation] = $this->dispatched();

        $id = $this->fileForAllocation($this->head, $allocation, ['handed_to' => 'Maria Santos'])->assertCreated()->json('data.id');

        $this->actingAs($this->head)
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'self_approval');

        $peer = User::factory()->bloodCenterStaff($this->centre, StaffRole::InventoryControlOfficer)->create();

        $this->actingAs($peer)
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertForbidden()
            ->assertJsonPath('code', 'not_the_approver');

        $this->actingAs($this->centerAdmin)->postJson("/api/blood-center/corrections/{$id}/approve")->assertOk();

        $this->assertSame('Maria Santos', $allocation->refresh()->handed_to);
    }

    public function test_another_facilitys_dispatch_is_not_found(): void
    {
        [$allocation] = $this->dispatched();
        $stranger = User::factory()->bloodCenterStaff(Facility::factory()->approved()->create(), StaffRole::DispatchCoordinator)->create();

        $this->fileForAllocation($stranger, $allocation, ['handed_to' => 'Maria Santos'])
            ->assertNotFound()
            ->assertJsonPath('code', 'allocation_not_found');
    }

    public function test_a_release_after_a_hospital_receipt_is_refused_at_approval(): void
    {
        [$allocation] = $this->dispatched();
        $allocation->update(['allocated_at' => now()->subHours(2), 'released_at' => now()->subHour()]);

        $id = $this->fileForAllocation($this->dispatcher, $allocation, ['released_at' => now()->subMinutes(30)->toIso8601String()])
            ->assertCreated()->json('data.id');

        // The hospital confirms receipt before the correction is decided, at a
        // moment earlier than the time the correction would set.
        $allocation->update(['received_at' => now()->subMinutes(40), 'received_by' => $this->requester->id]);

        $this->actingAs($this->head)
            ->postJson("/api/blood-center/corrections/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('changes.released_at');

        $this->assertSame('pending', CorrectionRequest::findOrFail($id)->status);
    }

    // --- General ---------------------------------------------------------------------

    public function test_the_donation_route_does_not_accept_an_issuance_subject(): void
    {
        $this->actingAs($this->centerAdmin)
            ->postJson('/api/blood-center/donations/1/corrections', [
                'subject' => 'unit_details',
                'reason' => 'x',
                'changes' => ['storage_location' => 'Freezer B'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject');
    }

    public function test_a_correction_must_name_exactly_the_target_its_subject_expects(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);

        $base = [
            'facility_id' => $this->centre->id,
            'subject' => CorrectionSubject::UnitDetails,
            'requested_by' => $this->clerk->id,
            'reason' => 'x',
            'changes' => ['storage_location' => 'y'],
        ];

        $this->expectException(LogicException::class);

        // Names a donation as well as the unit.
        CorrectionRequest::create([...$base, 'blood_unit_id' => $unit->id, 'donation_id' => $unit->donation_id]);
    }

    public function test_a_correction_with_no_target_is_refused(): void
    {
        $this->expectException(LogicException::class);

        CorrectionRequest::create([
            'facility_id' => $this->centre->id,
            'subject' => CorrectionSubject::UnitDetails,
            'requested_by' => $this->clerk->id,
            'reason' => 'x',
            'changes' => ['storage_location' => 'y'],
        ]);
    }

    public function test_the_user_payload_lists_the_corrections_each_account_may_file(): void
    {
        $custom = User::factory()->bloodCenterCustomStaff($this->centre, Department::Issuance, 'Stock Auditor')->create();

        $expected = [
            [$this->clerk, ['unit_details']],
            [$this->dispatcher, ['dispatch']],
            [$this->head, ['dispatch']],
            [User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create(), ['payment']],
            [User::factory()->bloodCenterStaff($this->centre, StaffRole::Phlebotomist)->create(), ['collection']],
            [$custom, []],
            [$this->centerAdmin, CorrectionSubject::values()],
        ];

        foreach ($expected as [$user, $subjects]) {
            $this->actingAs($user)
                ->getJson('/api/user')
                ->assertOk()
                ->assertJsonPath('data.correction_subjects', $subjects);
        }
    }

    public function test_the_head_reviews_issuance_corrections_and_other_heads_do_not(): void
    {
        $unit = $this->makeUnit(['id' => 'AVAIL-01']);
        $this->fileForUnit($this->clerk, $unit, ['storage_location' => 'Freezer B'])->assertCreated();

        $this->actingAs($this->head)
            ->getJson('/api/blood-center/corrections?scope=review&status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.target_label', 'Unit AVAIL-01')
            ->assertJsonPath('data.0.can_decide', true);

        foreach ([StaffRole::LabSupervisor, StaffRole::BillingSupervisor, StaffRole::ScreeningPhysician] as $role) {
            $this->actingAs(User::factory()->bloodCenterStaff($this->centre, $role)->create())
                ->getJson('/api/blood-center/corrections?scope=review&status=pending')
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }
    }

    public function test_release_records_who_took_the_units_on_the_allocation(): void
    {
        $request = $this->requestForStock();
        $unit = $this->makeUnit(['status' => BloodUnitStatus::Available]);

        $this->actingAs($this->head)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/allocate", ['quantity' => 1])
            ->assertOk();

        $this->actingAs($this->head)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/release", ['handed_to' => '  Pedro Cruz '])
            ->assertOk();

        $this->assertSame('Pedro Cruz', RequestAllocation::query()->where('unit_id', $unit->id)->value('handed_to'));
    }

    // --- Helpers ----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $changes
     */
    private function fileForUnit(User $as, BloodUnit $unit, array $changes): TestResponse
    {
        return $this->actingAs($as)->postJson("/api/blood-center/inventory/{$unit->id}/corrections", [
            'reason' => 'Entered wrongly at the counter.',
            'changes' => $changes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function fileForAllocation(User $as, RequestAllocation $allocation, array $changes): TestResponse
    {
        return $this->actingAs($as)->postJson("/api/blood-center/allocations/{$allocation->id}/corrections", [
            'reason' => 'Entered wrongly at the counter.',
            'changes' => $changes,
        ]);
    }

    /**
     * A request with one unit dispatched against it.
     *
     * @param  array<string, mixed>  $overrides  Allocation columns.
     * @return array{0: RequestAllocation, 1: BloodUnit}
     */
    private function dispatched(array $overrides = []): array
    {
        $request = $this->requestForStock();
        $unit = $this->makeUnit(['status' => BloodUnitStatus::Issued]);

        $allocation = RequestAllocation::factory()->holding($request, $unit)->released($this->head)->create([
            'allocated_by' => $this->head->id,
            'allocated_at' => now()->subHour(),
            'released_at' => now()->subMinutes(30),
            ...$overrides,
        ]);

        return [$allocation, $unit];
    }

    private function requestForStock(): BloodRequest
    {
        return BloodRequest::factory()
            ->raisedBy($this->hospital, $this->requester)
            ->addressedTo($this->centre)
            ->forStock($this->bloodType, $this->component, 1)
            ->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<int, string>  $states
     */
    private function makeUnit(array $overrides = [], array $states = [], ?int $facilityId = null): BloodUnit
    {
        $facilityId ??= $this->centre->id;

        $donation = Donation::factory()->create([
            'facility_id' => $facilityId,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        $factory = BloodUnit::factory();

        foreach ($states as $state) {
            $factory = $factory->{$state}();
        }

        return $factory->create([
            'facility_id' => $facilityId,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            ...$overrides,
        ]);
    }

    private function inDays(int $days): string
    {
        return OperationalDay::today()->addDays($days)->toDateString();
    }

    private function prepare(FormRequest $form): void
    {
        $form->setContainer(app())->setRedirector(app(Redirector::class));
        $form->setUserResolver(fn (): User => $this->dispatcher);
    }
}
