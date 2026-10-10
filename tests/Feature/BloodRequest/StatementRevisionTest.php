<?php

namespace Tests\Feature\BloodRequest;

use App\Enums\BillingStatus;
use App\Enums\StaffRole;
use App\Models\Billing;
use App\Models\BillingRevision;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\TestCase;

/**
 * Statements of Account: frozen, numbered revisions of a live statement.
 *
 * What a payer is shown never changes. A revision is reused while nothing on
 * the statement has moved and frozen anew when it does; the database itself
 * refuses to change one; numbers come from the issuing centre's own counter.
 */
class StatementRevisionTest extends TestCase
{
    use BuildsBillingFixtures, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(500);
    }

    private function issue(BloodRequest $request): TestResponse
    {
        return $this->actingAs($this->billingClerk)
            ->postJson("/api/blood-center/billings/{$request->id}/statements");
    }

    /**
     * Assert the database itself refuses a write, in a savepoint so PostgreSQL's transaction survives it.
     */
    private function assertRefusedByDatabase(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('The database should have refused that write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_issuing_freezes_a_numbered_statement(): void
    {
        $request = $this->allocatedRequest(2);

        $this->issue($request)
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('revision.document_number', "SOA-{$this->centre->id}-000001")
            ->assertJsonPath('revision.revision_number', 1)
            ->assertJsonPath('revision.total_amount', '1000.00')
            ->assertJsonPath('revision.amount_due', '1000.00')
            ->assertJsonPath('revision.lines.0.component_name', 'Packed Red Blood Cells')
            ->assertJsonPath('revision.lines.0.quantity', 2)
            ->assertJsonPath('revision.lines.0.unit_price', '500.00');
    }

    public function test_a_statement_names_its_issuer_who_it_bills_and_who_issued_it(): void
    {
        $this->centre->forceFill(['doh_license_number' => 'DOH-BC-0042', 'phone' => '082-123-4567'])->save();
        $request = $this->allocatedRequest(1);

        $this->issue($request)
            ->assertCreated()
            ->assertJsonPath('revision.issuer.name', $this->centre->name)
            ->assertJsonPath('revision.issuer.doh_license_number', 'DOH-BC-0042')
            ->assertJsonPath('revision.issuer.phone', '082-123-4567')
            ->assertJsonPath('revision.bill_to.patient_name', $request->patientFullName())
            ->assertJsonPath('revision.bill_to.facility', $this->hospital->name)
            ->assertJsonPath('revision.request_reference', $request->reference_number)
            ->assertJsonPath('revision.issued_by', trim($this->billingClerk->first_name.' '.$this->billingClerk->last_name));

        // A weekly order is the hospital's to settle, so it names no patient.
        $weekly = $this->allocatedRequest(1, replenishment: true);

        $this->issue($weekly)
            ->assertCreated()
            ->assertJsonPath('revision.statement_only', true)
            ->assertJsonPath('revision.bill_to.patient_name', null)
            ->assertJsonPath('revision.bill_to.facility', $this->hospital->name);
    }

    public function test_issuing_again_with_nothing_changed_reuses_the_statement_and_its_number(): void
    {
        $request = $this->allocatedRequest(1);

        $first = $this->issue($request)->assertCreated()->json('revision.id');

        $this->issue($request)
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('revision.id', $first);

        $this->assertSame(1, BillingRevision::query()->count());
        $this->assertSame(2, (int) DB::table('facility_document_sequences')->where('facility_id', $this->centre->id)->value('next_value'));
    }

    public function test_a_top_up_after_issue_freezes_a_new_revision_and_leaves_the_first_alone(): void
    {
        $request = $this->allocatedRequest(1, askedFor: 3);
        $this->issue($request)->assertCreated();

        $this->topUp($request, 2);

        $this->issue($request)
            ->assertCreated()
            ->assertJsonPath('revision.revision_number', 2)
            ->assertJsonPath('revision.document_number', "SOA-{$this->centre->id}-000002")
            ->assertJsonPath('revision.total_amount', '1500.00');

        $first = BillingRevision::query()->where('revision_number', 1)->firstOrFail();
        $this->assertSame('500.00', $first->total_amount);
        $this->assertSame(1, $first->items()->sole()->quantity);
    }

    public function test_a_partial_payment_is_pinned_to_a_revision_and_the_next_one_shows_the_reduced_balance(): void
    {
        $request = $this->allocatedRequest(2);

        $this->payCash($request, 400)->assertCreated();

        $payment = Payment::query()->sole();
        $this->assertNotNull($payment->billing_revision_id);
        $this->assertSame('1000.00', $payment->revision->amount_due);

        $this->issue($request)
            ->assertCreated()
            ->assertJsonPath('revision.collected_at_issue', '400.00')
            ->assertJsonPath('revision.amount_due', '600.00');
    }

    public function test_the_database_refuses_to_change_or_delete_an_issued_revision(): void
    {
        $request = $this->allocatedRequest(1);
        $revisionId = $this->issue($request)->json('revision.id');
        $itemId = BillingRevision::findOrFail($revisionId)->items()->sole()->id;

        $this->assertRefusedByDatabase(fn () => DB::table('billing_revisions')->where('id', $revisionId)->update(['amount_due' => '1.00']));
        $this->assertRefusedByDatabase(fn () => DB::table('billing_revisions')->where('id', $revisionId)->delete());
        $this->assertRefusedByDatabase(fn () => DB::table('billing_revision_items')->where('id', $itemId)->update(['quantity' => 9]));
        $this->assertRefusedByDatabase(fn () => DB::table('billing_revision_items')->where('id', $itemId)->delete());

        $this->assertSame('500.00', BillingRevision::findOrFail($revisionId)->amount_due);
    }

    public function test_the_model_refuses_to_change_an_issued_revision(): void
    {
        $request = $this->allocatedRequest(1);
        $revision = BillingRevision::findOrFail($this->issue($request)->json('revision.id'));

        $this->expectException(LogicException::class);

        $revision->update(['amount_due' => '1.00']);
    }

    public function test_numbers_are_kept_per_issuing_centre(): void
    {
        $request = $this->allocatedRequest(1);
        $this->issue($request)->assertCreated();

        $otherCentre = Facility::factory()->approved()->create();
        $otherClerk = User::factory()->bloodCenterStaff($otherCentre, StaffRole::BillingClerk)->create();
        $theirs = BloodRequest::factory()->raisedBy($this->hospital, $this->requester)->addressedTo($otherCentre)->create();
        Billing::factory()->chargeable(300)->create(['request_id' => $theirs->id]);

        $this->actingAs($otherClerk)
            ->postJson("/api/blood-center/billings/{$theirs->id}/statements")
            ->assertCreated()
            ->assertJsonPath('revision.document_number', "SOA-{$otherCentre->id}-000001");
    }

    public function test_a_number_that_keeps_colliding_is_refused_rather_than_failing(): void
    {
        $request = $this->allocatedRequest(1);

        // A number already taken outside the counter: every retry draws it again.
        $elsewhere = Billing::factory()->paid(0)->create([
            'request_id' => BloodRequest::factory()->raisedBy($this->hospital, $this->requester)->addressedTo($this->centre)->create()->id,
        ]);
        DB::table('billing_revisions')->insert([
            'billing_id' => $elsewhere->id,
            'revision_number' => 1,
            'document_number' => "SOA-{$this->centre->id}-000001",
            'issuing_facility_id' => $this->centre->id,
            'payer_facility_id' => $this->hospital->id,
            'currency' => 'PHP',
            'total_amount' => '0.00',
            'collected_at_issue' => '0.00',
            'amount_due' => '0.00',
            'statement_only' => false,
            'billing_status' => 'paid',
            'reason' => 'statement',
            'created_at' => now(),
        ]);

        $this->issue($request)
            ->assertStatus(409)
            ->assertJsonPath('code', 'document_number_conflict');
    }

    public function test_a_voided_statement_issues_no_statement(): void
    {
        $request = $this->allocatedRequest(1);
        $request->billing()->firstOrFail()->update(['status' => BillingStatus::Void]);

        $this->issue($request)->assertStatus(409)->assertJsonPath('code', 'billing_void');
    }

    public function test_issuing_is_the_billing_departments_but_reading_is_open_to_billing_view(): void
    {
        $request = $this->allocatedRequest(1);

        $this->actingAs($this->inventoryStaff)
            ->postJson("/api/blood-center/billings/{$request->id}/statements")
            ->assertForbidden();

        $this->issue($request)->assertCreated();

        $this->actingAs($this->inventoryStaff)
            ->getJson("/api/blood-center/billings/{$request->id}/statements")
            ->assertOk()
            ->assertJsonCount(1, 'statements');
    }

    public function test_the_statement_downloads_as_a_pdf_for_the_centre_and_the_requesting_hospital_only(): void
    {
        $request = $this->allocatedRequest(1);
        $revisionId = $this->issue($request)->json('revision.id');

        $this->actingAs($this->billingClerk)
            ->get("/api/blood-center/statements/{$revisionId}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->requester)
            ->get("/api/hospital/statements/{$revisionId}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $this->actingAs(User::factory()->bloodBankStaff($otherHospital)->create())
            ->getJson("/api/hospital/statements/{$revisionId}/pdf")
            ->assertNotFound()
            ->assertJsonPath('code', 'statement_not_found');

        $otherCentre = Facility::factory()->approved()->create();
        $this->actingAs(User::factory()->bloodCenterStaff($otherCentre, StaffRole::BillingClerk)->create())
            ->getJson("/api/blood-center/statements/{$revisionId}/pdf")
            ->assertNotFound();
    }

    public function test_the_hospital_reads_its_own_statement_revisions_and_receipts_without_payment_references(): void
    {
        $request = $this->allocatedRequest(2);
        $this->payCash($request, 1000, ['payment_method' => 'gcash', 'reference_number' => 'GC-SECRET-1'])->assertCreated();

        $body = $this->actingAs($this->requester)
            ->getJson("/api/hospital/blood-requests/{$request->id}/billing")
            ->assertOk()
            ->assertJsonPath('billing.status', BillingStatus::Paid->value)
            ->assertJsonCount(1, 'statements')
            ->assertJsonCount(1, 'receipts')
            ->json();

        $this->assertStringNotContainsString('GC-SECRET-1', json_encode($body));

        $otherHospital = Facility::factory()->bloodBank()->approved()->create();
        $this->actingAs(User::factory()->bloodBankStaff($otherHospital)->create())
            ->getJson("/api/hospital/blood-requests/{$request->id}/billing")
            ->assertNotFound();
    }
}
