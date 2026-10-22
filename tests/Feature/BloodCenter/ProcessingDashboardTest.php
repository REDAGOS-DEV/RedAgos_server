<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\ClearanceKind;
use App\Enums\CorrectionSubject;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\CorrectionRequest;
use App\Models\Donation;
use App\Models\DonationClearance;
use App\Models\DonationComponent;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The Processing dashboard (project owner, 2026-10-22).
 *
 * What is locked in: it counts this centre only; the queue follows the bench
 * (separate, wait for Testing, label, hand over); separation is timed from
 * the first breakdown, not one a correction re-created; a unit handed over
 * before it was cleared stays out of the hand-over time; the corrections and
 * rejections go only to the component technologist; and only Processing
 * reads it.
 */
class ProcessingDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const URL = '/api/blood-center/laboratory/processing-dashboard';

    private Facility $facility;

    private User $technologist;

    private User $donor;

    private BloodComponent $prbc;

    private BloodComponent $ffp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->technologist = User::factory()->bloodCenterStaff($this->facility, StaffRole::ComponentTechnologist)->create();
        // One donor gives every donation: the factory hands each donor a
        // unique blood type, and there are only eight.
        $this->donor = User::factory()->donor()->create();
        $this->prbc = BloodComponent::factory()->create(['name' => 'Packed RBC']);
        $this->ffp = BloodComponent::factory()->create(['name' => 'Fresh Frozen Plasma']);
    }

    /**
     * A moment on an operational day, as the database stores it.
     */
    private function at(int $daysFromToday, int $hour = 10): CarbonImmutable
    {
        return OperationalDay::today()->startOfDay()->addDays($daysFromToday)->setTime($hour, 0)
            ->setTimezone((string) config('app.timezone'));
    }

    private function drawn(int $daysFromToday = 0, int $hour = 8, DonationStatus $status = DonationStatus::Collected, ?Facility $at = null): Donation
    {
        return Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => ($at ?? $this->facility)->id,
            'donation_date' => $this->at($daysFromToday, $hour),
            'status' => $status,
        ]);
    }

    /**
     * Processing's breakdown, and the audit entry that times it.
     *
     * @param  array<int, BloodComponent>  $bags
     */
    private function separate(Donation $donation, array $bags, ?CarbonImmutable $at = null): void
    {
        foreach ($bags as $component) {
            DonationComponent::factory()->create([
                'donation_id' => $donation->id,
                'component_id' => $component->id,
                'quantity' => 1,
                'volume_ml' => 200,
            ]);
        }

        $this->audit('laboratory.components_declared', $donation, $at ?? CarbonImmutable::now());
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(string $action, Donation $donation, CarbonImmutable $at, array $context = []): void
    {
        $row = AuditLog::create([
            'actor_id' => $this->technologist->id,
            'facility_id' => $donation->facility_id,
            'action' => $action,
            'auditable_type' => Donation::class,
            'auditable_id' => $donation->id,
            'context' => $context === [] ? null : $context,
        ]);

        $row->forceFill(['created_at' => $at])->save();
    }

    private function clear(Donation $donation, ?CarbonImmutable $at = null, bool $atTheBench = true): void
    {
        foreach (ClearanceKind::cases() as $kind) {
            DonationClearance::create([
                'donation_id' => $donation->id,
                'kind' => $kind,
                'issued_by' => $atTheBench ? $this->technologist->id : null,
                'issued_at' => $at ?? now(),
                'source' => 'test',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(?User $as = null): array
    {
        return $this->actingAs($as ?? $this->technologist)->getJson(self::URL)->assertOk()->json();
    }

    public function test_the_queue_follows_the_bench(): void
    {
        $unseparated = $this->drawn(0, 7);
        $this->drawn(0, 9);

        $waitingOnTesting = $this->drawn();
        $this->separate($waitingOnTesting, [$this->prbc]);

        $toLabel = $this->drawn();
        $this->separate($toLabel, [$this->prbc]);
        $this->clear($toLabel);

        $toHandOver = $this->drawn();
        $this->separate($toHandOver, [$this->prbc]);
        $this->clear($toHandOver);
        $toHandOver->update(['labelled_at' => now()]);

        // Off the bench: handed over, rejected, or another centre's.
        $this->separate($this->drawn(status: DonationStatus::Completed), [$this->prbc]);
        $this->drawn(status: DonationStatus::Rejected);
        $this->drawn(at: Facility::factory()->approved()->create());

        $queue = $this->dashboard()['queue'];

        $this->assertSame(5, $queue['total']);
        $this->assertSame(2, $queue['to_separate']);
        $this->assertSame(1, $queue['awaiting_testing']);
        $this->assertSame(1, $queue['to_label']);
        $this->assertSame(1, $queue['to_hand_over']);
        $this->assertTrue(CarbonImmutable::parse($queue['oldest_unseparated_at'])->equalTo($this->drawnAt($unseparated)));
    }

    public function test_separation_is_timed_from_the_first_breakdown(): void
    {
        $today = $this->drawn(0, 8);
        $this->separate($today, [$this->prbc, $this->prbc, $this->ffp], $this->at(0, 11));
        // A correction later re-declares it: still separated at 11:00.
        $this->audit('laboratory.components_declared', $today, $this->at(0, 15));

        $yesterday = $this->drawn(-1, 9);
        $this->separate($yesterday, [$this->prbc], $this->at(-1, 10));

        // Outside the 30 days.
        $this->separate($this->drawn(-40, 9), [$this->ffp], $this->at(-40, 10));

        $data = $this->dashboard();

        $this->assertSame(1, $data['today']['separated']);
        $this->assertSame(3, $data['today']['bags']);
        // JSON carries 2.0 as 2.
        $this->assertEquals(['median_hours' => 2, 'units' => 2], $data['separation']);
        $this->assertSame(
            [['component' => 'Packed RBC', 'bags' => 3], ['component' => 'Fresh Frozen Plasma', 'bags' => 1]],
            $data['by_component']
        );

        $this->assertCount(30, $data['trend']);
        $this->assertSame(['date' => OperationalDay::todayAsDate(), 'bags' => 3, 'units' => 1], $data['trend'][29]);
        $this->assertSame(1, $data['trend'][28]['bags']);
    }

    public function test_the_hand_over_is_timed_from_the_clearance(): void
    {
        $labelled = $this->drawn(0, 7);
        $this->separate($labelled, [$this->prbc], $this->at(0, 8));
        $this->clear($labelled, $this->at(0, 10));
        $labelled->update(['labelled_at' => $this->at(0, 13), 'status' => DonationStatus::Completed]);
        $this->audit('laboratory.donation_completed', $labelled, $this->at(0, 14));

        // Handed over before it was cleared, under the old order: not timed.
        $early = $this->drawn(-2, 7);
        $this->separate($early, [$this->prbc], $this->at(-2, 8));
        $this->audit('laboratory.donation_completed', $early, $this->at(-2, 9));
        $this->clear($early, $this->at(-1, 10));

        $data = $this->dashboard();

        $this->assertEquals(['median_hours' => 4, 'units' => 1], $data['handover']);
        $this->assertSame(1, $data['today']['handed_over']);
        $this->assertSame(1, $data['today']['labelled']);
    }

    public function test_the_corrections_and_rejections_go_only_to_the_component_technologist(): void
    {
        $donation = $this->drawn();
        $this->separate($donation, [$this->prbc]);

        CorrectionRequest::create([
            'facility_id' => $this->facility->id,
            'donation_id' => $donation->id,
            'subject' => CorrectionSubject::Components,
            'requested_by' => $this->technologist->id,
            'reason' => 'The second bag was not recorded.',
            'changes' => ['components' => [['component_id' => $this->prbc->id, 'volume_ml' => 200]]],
            'previous' => ['components' => []],
        ]);

        // Rejected by Processing, and by a reactive result (Testing's, not counted).
        $this->audit('laboratory.donation_rejected', $this->drawn(status: DonationStatus::Rejected), $this->at(-3));
        $this->audit('laboratory.donation_rejected', $this->drawn(status: DonationStatus::Rejected), $this->at(-3), [
            'automatic' => true,
            'cause' => 'reactive_serology',
        ]);

        $this->assertSame(['corrections' => 1, 'rejected_recently' => 1], $this->dashboard()['technologist']);

        $assistant = User::factory()->bloodCenterStaff($this->facility, StaffRole::ProcessingAssistant)->create();
        $this->assertNull($this->dashboard($assistant)['technologist']);
    }

    public function test_only_processing_reads_it(): void
    {
        $testing = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $this->actingAs($testing)->getJson(self::URL)->assertForbidden();

        $issuance = User::factory()->bloodCenterStaff($this->facility, StaffRole::InventoryControlOfficer)->create();
        $this->actingAs($issuance)->getJson(self::URL)->assertForbidden();
    }

    public function test_it_names_no_donor(): void
    {
        $this->separate($this->drawn(), [$this->prbc]);

        $body = $this->actingAs($this->technologist)->getJson(self::URL)->assertOk()->getContent();

        $this->assertStringNotContainsString($this->donor->first_name, $body);
        $this->assertStringNotContainsString($this->donor->last_name, $body);
    }

    private function drawnAt(Donation $donation): CarbonImmutable
    {
        return CarbonImmutable::parse($donation->collection?->ended_at ?? $donation->donation_date);
    }
}
