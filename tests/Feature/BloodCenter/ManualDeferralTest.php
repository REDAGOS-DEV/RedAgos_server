<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\DonorDeferral;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The Deferrals page (project owner, 2026-10-18): a deferral recorded on a
 * donor by hand, outside any visit, and lifted later.
 *
 * What is locked in: one recorded here reaches every screen that asks whether
 * a donor is deferred; a temporary one ends on its Until date; a lifted one
 * stops counting but stays listed; only the physician records or lifts, only
 * the recording centre sees the reason; and the audit trail never copies it.
 */
class ManualDeferralTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $physician;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $this->receptionist = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
    }

    /**
     * A donor who gave at the given centre (this one by default) a year ago.
     */
    private function donorOf(?Facility $at = null): User
    {
        $donor = User::factory()->donor()->create();

        Donation::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => ($at ?? $this->facility)->id,
            'donation_date' => now()->subYear(),
            'status' => DonationStatus::Completed,
        ]);

        return $donor;
    }

    /**
     * An operational date some days from today, as the form sends it. The
     * suite runs in UTC; the centre's day is Manila's.
     */
    private function day(int $offset): string
    {
        return OperationalDay::today()->addDays($offset)->toDateString();
    }

    private function codeOf(User $donor): string
    {
        return 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(User $donor, array $overrides = []): array
    {
        return [
            'donor_uuid' => $donor->uuid,
            'outcome' => 'permanently_deferred',
            'reason' => 'Chronic condition',
            ...$overrides,
        ];
    }

    /**
     * The donor's row on the Donors list, as the receptionist reads it.
     *
     * @return array<string, mixed>
     */
    private function donorRow(User $donor): array
    {
        $row = collect(
            $this->actingAs($this->receptionist)->getJson('/api/blood-center/donors')->assertOk()->json('data')
        )->firstWhere('uuid', $donor->uuid);

        $this->assertNotNull($row, 'The donor is not on the list.');

        return $row;
    }

    // --- Recording ---------------------------------------------------------------

    public function test_a_permanent_deferral_reaches_the_donors_list_and_the_counter(): void
    {
        $donor = $this->donorOf();
        $this->assertSame('eligible', $this->donorRow($donor)['eligibility']);

        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferrals', $this->payload($donor))
            ->assertCreated()
            ->assertJsonPath('code', fn (string $code) => str_starts_with($code, 'DEF-'))
            ->assertJsonPath('kind', 'permanent')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('deferred_on', OperationalDay::todayAsDate())
            ->assertJsonPath('ends_on', null)
            ->assertJsonPath('donor.full_name', trim($donor->first_name.' '.$donor->last_name))
            ->assertJsonPath('can.lift', true);

        $row = $this->donorRow($donor);
        $this->assertSame('deferred', $row['eligibility']);
        $this->assertSame('permanent', $row['deferral']['kind']);

        // The counter's lookup carries the prior-deferral notice.
        $this->actingAs($this->physician)
            ->getJson('/api/blood-center/donors/lookup?type=donor_code&value='.$this->codeOf($donor))
            ->assertOk()
            ->assertJsonPath('prior_deferral.outcome', 'permanently_deferred');
    }

    public function test_the_until_date_is_ignored_for_a_permanent_deferral(): void
    {
        $donor = $this->donorOf();

        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferrals', $this->payload($donor, ['ends_on' => $this->day(30)]))
            ->assertCreated()
            ->assertJsonPath('ends_on', null);
    }

    public function test_a_temporary_deferral_needs_an_until_date_and_ends_on_it(): void
    {
        $donor = $this->donorOf();

        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferrals', $this->payload($donor, ['outcome' => 'temporarily_deferred']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_on');

        $until = $this->day(2);

        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferrals', $this->payload($donor, [
                'outcome' => 'temporarily_deferred',
                'reason' => 'Low haemoglobin',
                'ends_on' => $until,
            ]))
            ->assertCreated()
            ->assertJsonPath('kind', 'temporary')
            ->assertJsonPath('ends_on', $until);

        $row = $this->donorRow($donor);
        $this->assertSame('deferred', $row['eligibility']);
        $this->assertSame('temporary', $row['deferral']['kind']);
        $this->assertSame($until, $row['deferral']['ends_on']);

        // The day after the Until date, the donor is free again and the page
        // calls the deferral expired.
        $this->travelTo(now()->addDays(3));

        $this->assertSame('eligible', $this->donorRow($donor)['eligibility']);

        $this->actingAs($this->physician)
            ->getJson('/api/blood-center/deferrals?status=expired')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'expired')
            ->assertJsonPath('data.0.can.lift', false);
    }

    public function test_a_deferral_cannot_start_in_the_future_or_end_before_it_starts(): void
    {
        $donor = $this->donorOf();

        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferrals', $this->payload($donor, ['deferred_on' => $this->day(1)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deferred_on');

        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferrals', $this->payload($donor, [
                'outcome' => 'temporarily_deferred',
                'ends_on' => $this->day(-1),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_on');
    }

    public function test_only_a_donor_this_centre_has_dealt_with_can_be_deferred_here(): void
    {
        $elsewhere = $this->donorOf(Facility::factory()->approved()->create());

        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferrals', $this->payload($elsewhere))
            ->assertForbidden()
            ->assertJsonPath('code', 'donor_not_at_facility');

        $this->assertDatabaseCount('donor_deferrals', 0);
    }

    public function test_the_receptionist_can_neither_record_nor_read_deferrals(): void
    {
        $donor = $this->donorOf();

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/deferrals', $this->payload($donor))
            ->assertForbidden();

        $this->actingAs($this->receptionist)
            ->getJson('/api/blood-center/deferrals')
            ->assertForbidden();
    }

    public function test_the_audit_trail_records_the_outcome_never_the_reason(): void
    {
        $donor = $this->donorOf();

        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferrals', $this->payload($donor, ['reason' => 'Hepatitis B history']))
            ->assertCreated();

        $log = AuditLog::query()->where('action', 'donor.deferred')->sole();

        $this->assertSame($donor->id, (int) $log->auditable_id);
        $this->assertSame('permanently_deferred', $log->context['outcome']);
        $this->assertStringNotContainsString('Hepatitis', json_encode($log->context));
    }

    // --- Lifting -----------------------------------------------------------------

    public function test_lifting_frees_the_donor_and_keeps_the_record(): void
    {
        $donor = $this->donorOf();

        $id = $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferrals', $this->payload($donor))
            ->assertCreated()
            ->json('id');

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/deferrals/{$id}/lift", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/deferrals/{$id}/lift", ['reason' => 'Specialist cleared the donor'])
            ->assertOk()
            ->assertJsonPath('status', 'lifted')
            ->assertJsonPath('lift_reason', 'Specialist cleared the donor')
            ->assertJsonPath('lifted_by', trim($this->physician->first_name.' '.$this->physician->last_name))
            ->assertJsonPath('can.lift', false);

        $this->assertSame('eligible', $this->donorRow($donor)['eligibility']);

        $this->actingAs($this->physician)
            ->getJson('/api/blood-center/deferrals?status=lifted')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/deferrals/{$id}/lift", ['reason' => 'Again'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'deferral_already_lifted');

        $this->assertDatabaseHas('audit_logs', ['action' => 'donor.deferral_lifted', 'auditable_id' => $donor->id]);
    }

    public function test_another_centre_neither_lists_nor_lifts_it_but_still_sees_the_donor_deferred(): void
    {
        $donor = $this->donorOf();
        $other = Facility::factory()->approved()->create();
        Donation::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => $other->id,
            'donation_date' => now()->subMonths(8),
            'status' => DonationStatus::Completed,
        ]);
        $otherPhysician = User::factory()->bloodCenterStaff($other, StaffRole::ScreeningPhysician)->create();

        $id = $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferrals', $this->payload($donor))
            ->assertCreated()
            ->json('id');

        $this->actingAs($otherPhysician)
            ->getJson('/api/blood-center/deferrals')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($otherPhysician)
            ->postJson("/api/blood-center/deferrals/{$id}/lift", ['reason' => 'Not ours'])
            ->assertNotFound();

        $this->actingAs($otherPhysician)
            ->getJson('/api/blood-center/donors/lookup?type=donor_code&value='.$this->codeOf($donor))
            ->assertOk()
            ->assertJsonPath('prior_deferral.outcome', 'permanently_deferred');
    }

    // --- The list ----------------------------------------------------------------

    public function test_the_list_searches_and_filters_this_centres_deferrals(): void
    {
        $rajesh = User::factory()->donor()->create(['first_name' => 'Rajesh', 'last_name' => 'Pandey']);
        $rekha = User::factory()->donor()->create(['first_name' => 'Rekha', 'last_name' => 'Sharma']);

        $active = DonorDeferral::factory()->create([
            'donor_id' => $rajesh->id,
            'facility_id' => $this->facility->id,
            'reason' => 'Low haemoglobin',
        ]);
        DonorDeferral::factory()->permanent()->lifted()->create([
            'donor_id' => $rekha->id,
            'facility_id' => $this->facility->id,
            'reason' => 'Chronic condition',
        ]);
        DonorDeferral::factory()->create(['reason' => 'Elsewhere']);

        $this->actingAs($this->physician)
            ->getJson('/api/blood-center/deferrals')
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->actingAs($this->physician)
            ->getJson('/api/blood-center/deferrals?search=rajesh')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->actingAs($this->physician)
            ->getJson('/api/blood-center/deferrals?search=chronic')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'lifted');

        $this->actingAs($this->physician)
            ->getJson('/api/blood-center/deferrals?search=DEF-'.str_pad((string) $active->id, 6, '0', STR_PAD_LEFT))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->physician)
            ->getJson('/api/blood-center/deferrals?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Low haemoglobin');
    }
}
