<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\CorrectionSubject;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\CorrectionRequest;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\DonationClearance;
use App\Models\DonationImmunohematology;
use App\Models\DonationSerology;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The Testing dashboard.
 *
 * What is locked in: it counts this centre only, the queue follows the
 * Testing page's own rule, "today" is the operational day, turnaround ignores
 * backfilled clearances, the referral and approval counts go only to the
 * roles that work them, and Processing, which is never told which marker was
 * reactive, cannot read it.
 */
class TestingDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const URL = '/api/blood-center/laboratory/testing-dashboard';

    private Facility $facility;

    private User $technologist;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->technologist = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        // One donor gives every donation: the factory hands each donor a
        // unique blood type, and there are only eight.
        $this->donor = User::factory()->donor()->create();
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

    private function clear(Donation $donation, string $kind, CarbonImmutable $at, bool $atTheBench = true): void
    {
        DonationClearance::create([
            'donation_id' => $donation->id,
            'kind' => $kind,
            'issued_by' => $atTheBench ? $this->technologist->id : null,
            'issued_at' => $at,
            'source' => 'test',
        ]);
    }

    private function panel(Donation $donation, CarbonImmutable $at, array $reactive = [], bool $verified = true): void
    {
        DonationSerology::create([
            'donation_id' => $donation->id,
            'hiv' => in_array('hiv', $reactive, true) ? 'reactive' : 'non_reactive',
            'hbsag' => in_array('hbsag', $reactive, true) ? 'reactive' : 'non_reactive',
            'hcv' => 'non_reactive',
            'syphilis' => 'non_reactive',
            'malaria' => 'non_reactive',
            'recorded_by' => $this->technologist->id,
            'recorded_at' => $at,
            'verified_by' => $reactive !== [] && $verified ? $this->technologist->id : null,
            'verified_at' => $reactive !== [] && $verified ? $at : null,
        ]);
    }

    private function typing(Donation $donation, CarbonImmutable $at): void
    {
        DonationImmunohematology::create([
            'donation_id' => $donation->id,
            'blood_type_id' => $this->donor->donorProfile->blood_type_id,
            'forward_group' => 'O',
            'reverse_group' => 'A',
            'antibody_screen' => 'negative',
            'recorded_by' => $this->technologist->id,
            'recorded_at' => $at,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(?User $as = null): array
    {
        return $this->actingAs($as ?? $this->technologist)->getJson(self::URL)->assertOk()->json();
    }

    public function test_processing_cannot_read_it(): void
    {
        $processing = User::factory()->bloodCenterStaff($this->facility, StaffRole::ComponentTechnologist)->create();

        $this->actingAs($processing)->getJson(self::URL)->assertForbidden();
    }

    public function test_the_queue_counts_what_waits_for_each_section_at_this_centre_only(): void
    {
        // Waits for both sections.
        $untested = $this->drawn(-2, 9);

        // Typed but held, serology cleared.
        $held = $this->drawn();
        $this->typing($held, $this->at(0, 9));
        $this->panel($held, $this->at(0, 9));
        $this->clear($held, 'tti', $this->at(0, 9));

        // Typing cleared, a reactive panel not yet verified.
        $toVerify = $this->drawn();
        $this->clear($toVerify, 'immunohematology', $this->at(0, 9));
        $this->panel($toVerify, $this->at(0, 9), ['hiv'], verified: false);

        // Not waiting: cleared, rejected, or another centre's.
        $done = $this->drawn();
        $this->clear($done, 'tti', $this->at(0, 9));
        $this->clear($done, 'immunohematology', $this->at(0, 9));
        $this->drawn(status: DonationStatus::Rejected);
        $this->drawn(-5, at: Facility::factory()->approved()->create());

        $queue = $this->dashboard()['queue'];

        $this->assertSame(3, $queue['total']);
        $this->assertSame(1, $queue['awaiting_typing']);
        $this->assertSame(1, $queue['typing_held']);
        $this->assertSame(1, $queue['awaiting_serology']);
        $this->assertSame(1, $queue['awaiting_verification']);
        $this->assertTrue(CarbonImmutable::parse($queue['oldest_drawn_at'])->equalTo($untested->donation_date));
    }

    public function test_today_the_trend_and_the_marker_split_read_the_panels_recorded(): void
    {
        $this->panel($this->drawn(), $this->at(0, 9));
        $this->panel($this->drawn(), $this->at(0, 11), ['hiv']);
        $this->panel($this->drawn(-3), $this->at(-3), ['hbsag', 'hiv']);
        // Outside the 30 days.
        $this->panel($this->drawn(-40), $this->at(-40), ['hbsag']);
        $this->typing($this->drawn(), $this->at(0, 12));

        $data = $this->dashboard();

        $this->assertSame(OperationalDay::todayAsDate(), $data['today']['date']);
        $this->assertSame(2, $data['today']['panels']);
        $this->assertSame(1, $data['today']['reactive']);
        $this->assertSame(1, $data['today']['typings']);

        $this->assertCount(30, $data['trend']);
        $this->assertSame(['date' => OperationalDay::todayAsDate(), 'non_reactive' => 1, 'reactive' => 1], end($data['trend']));
        $this->assertSame(1, $data['trend'][26]['reactive']);

        $byMarker = collect($data['reactive_by_marker'])->pluck('count', 'marker')->all();
        $this->assertSame(['hiv' => 2, 'hbsag' => 1, 'hcv' => 0, 'syphilis' => 0, 'malaria' => 0], $byMarker);
    }

    public function test_turnaround_is_the_median_from_draw_to_the_second_clearance(): void
    {
        // All three cleared yesterday.
        foreach ([4, 6, 12] as $hours) {
            $unit = $this->drawn(-1, 6);
            $this->clear($unit, 'tti', $this->at(-1, 7));
            $this->clear($unit, 'immunohematology', $this->at(-1, 6)->addHours($hours));
        }

        // Cleared today, two hours after the draw.
        $today = $this->drawn(0, 6);
        $this->clear($today, 'tti', $this->at(0, 7));
        $this->clear($today, 'immunohematology', $this->at(0, 8));

        // Backfilled, not earned at the bench: says nothing about turnaround.
        $backfilled = $this->drawn(-10, 6);
        $this->clear($backfilled, 'tti', $this->at(-1), atTheBench: false);
        $this->clear($backfilled, 'immunohematology', $this->at(-1), atTheBench: false);

        $data = $this->dashboard();

        $this->assertSame(['median_hours' => 5, 'units' => 4], $data['turnaround']);
        $this->assertSame(1, $data['today']['cleared']);
    }

    public function test_approvals_and_referrals_go_only_to_the_roles_that_work_them(): void
    {
        $reactive = $this->drawn(status: DonationStatus::Rejected);
        CounsellingReferral::create(['donation_id' => $reactive->id, 'donor_id' => $this->donor->id, 'facility_id' => $this->facility->id]);

        $closed = $this->drawn(-3, status: DonationStatus::Rejected);
        CounsellingReferral::create([
            'donation_id' => $closed->id,
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'status' => 'closed',
            'closed_at' => $this->at(-1),
        ]);

        $typed = $this->drawn();
        $this->typing($typed, $this->at(0, 9));
        CorrectionRequest::create([
            'facility_id' => $this->facility->id,
            'donation_id' => $typed->id,
            'subject' => CorrectionSubject::Immunohematology,
            'requested_by' => $this->technologist->id,
            'reason' => 'Misread the reverse group.',
            'changes' => ['reverse_group' => 'O'],
            'previous' => ['reverse_group' => 'A'],
        ]);

        $technologist = $this->dashboard();
        $this->assertNull($technologist['approvals']);
        $this->assertNull($technologist['referrals']);

        $supervisor = $this->dashboard(User::factory()->bloodCenterStaff($this->facility, StaffRole::LabSupervisor)->create());
        $this->assertSame(['corrections' => 1], $supervisor['approvals']);
        $this->assertSame(1, $supervisor['referrals']['pending']);
        $this->assertSame(1, $supervisor['referrals']['no_contact_request']);
        $this->assertSame(1, $supervisor['referrals']['closed_recently']);
    }

    public function test_it_names_no_donor(): void
    {
        $unit = $this->drawn();
        $this->panel($unit, $this->at(0, 9), ['hiv'], verified: false);

        $body = $this->actingAs($this->technologist)->getJson(self::URL)->assertOk()->getContent();

        $this->assertStringNotContainsString($this->donor->first_name, $body);
        $this->assertStringNotContainsString($this->donor->email, $body);
        $this->assertStringNotContainsString('"donation_id"', $body);
    }
}
