<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\CounsellingReferral;
use App\Models\Donation;
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
 * The Testing department's reports for a period (TestingReportService).
 *
 * What is locked in: only Testing reads it, it counts this centre's panels in
 * the period and no others, marker by marker and month by month, who was
 * tested by sex and type of donor, the typings by blood group, and the
 * referrals moved in the period.
 */
class TestingReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const URL = '/api/blood-center/reports/testing';

    private Facility $facility;

    private User $technologist;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->technologist = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $this->donor = User::factory()->donor()->create();
    }

    private function at(int $daysFromToday, int $hour = 10): CarbonImmutable
    {
        return OperationalDay::today()->startOfDay()->addDays($daysFromToday)->setTime($hour, 0)
            ->setTimezone((string) config('app.timezone'));
    }

    private function drawn(int $daysFromToday = 0, ?Facility $at = null, array $extra = []): Donation
    {
        return Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => ($at ?? $this->facility)->id,
            'donation_date' => $this->at($daysFromToday, 8),
            'status' => DonationStatus::Collected,
            ...$extra,
        ]);
    }

    private function panel(Donation $donation, CarbonImmutable $at, array $reactive = []): void
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
            'verified_by' => $reactive !== [] ? $this->technologist->id : null,
            'verified_at' => $reactive !== [] ? $at : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(int $fromDaysAgo = 6, ?User $as = null): array
    {
        $from = OperationalDay::today()->subDays($fromDaysAgo)->toDateString();
        $to = OperationalDay::today()->toDateString();

        return $this->actingAs($as ?? $this->technologist)->getJson(self::URL."?from={$from}&to={$to}")->assertOk()->json();
    }

    public function test_only_testing_reads_it(): void
    {
        $processing = User::factory()->bloodCenterStaff($this->facility, StaffRole::ComponentTechnologist)->create();
        $from = OperationalDay::today()->subDays(6)->toDateString();
        $to = OperationalDay::today()->toDateString();

        $this->actingAs($processing)->getJson(self::URL."?from={$from}&to={$to}")->assertForbidden();
    }

    public function test_it_counts_this_centres_panels_in_the_period_marker_by_marker(): void
    {
        $this->panel($this->drawn(-1), $this->at(-1), ['hbsag']);
        $this->panel($this->drawn(-1), $this->at(-1), ['hiv', 'hbsag']);
        $this->panel($this->drawn(0), $this->at(0));
        $this->panel($this->drawn(0), $this->at(0));

        // Another centre's, and one from before the period: neither counts.
        $this->panel($this->drawn(0, Facility::factory()->approved()->create()), $this->at(0), ['hiv']);
        $this->panel($this->drawn(-20), $this->at(-20), ['hiv']);

        $report = $this->report();

        $this->assertSame(4, $report['serology']['tested']);
        $this->assertSame(2, $report['serology']['reactive']);
        $this->assertSame(50, (int) $report['serology']['reactive_rate']);

        $markers = collect($report['serology']['by_marker'])->keyBy('marker');
        $this->assertSame(2, $markers['hbsag']['reactive']);
        $this->assertSame(1, $markers['hiv']['reactive']);
        $this->assertSame(0, $markers['malaria']['reactive']);

        $this->assertSame(4, $report['total']['tested']);
        $this->assertSame(4, array_sum(array_column($report['months'], 'tested')));
        $this->assertSame(4, array_sum(array_column($report['daily'], 'panels')));
    }

    public function test_it_splits_the_tested_by_type_of_donor_and_sex(): void
    {
        $this->panel($this->drawn(0, null, ['donation_type' => 'ts']), $this->at(0), ['hbsag']);
        $this->panel($this->drawn(0, null, ['donation_type' => 'walk_in']), $this->at(0));

        $report = $this->report();
        $kinds = collect($report['profile']['kind'])->keyBy('key');

        $this->assertSame(1, $kinds['ts']['tested']);
        $this->assertSame(1, $kinds['ts']['reactive']);
        $this->assertSame(1, $kinds['walk_in']['tested']);
        $this->assertSame(0, $kinds['walk_in']['reactive']);
        $this->assertSame(2, array_sum(array_column($report['profile']['sex'], 'tested')));
    }

    public function test_it_counts_typings_by_group_and_the_referrals_moved(): void
    {
        DonationImmunohematology::create([
            'donation_id' => $this->drawn(0)->id,
            'blood_type_id' => $this->donor->donorProfile->blood_type_id,
            'forward_group' => 'O',
            'recorded_by' => $this->technologist->id,
            'recorded_at' => $this->at(0),
        ]);

        CounsellingReferral::create([
            'donation_id' => $this->drawn(-1)->id,
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'status' => 'contacted',
            'contacted_at' => $this->at(0),
        ]);

        $report = $this->report();

        $this->assertSame(1, $report['typing']['typed']);
        $this->assertSame(1, array_sum(array_column($report['typing']['by_group'], 'count')));
        $this->assertSame(1, $report['referrals']['opened']);
        $this->assertSame(1, $report['referrals']['contacted']);
        $this->assertSame(0, $report['referrals']['closed']);
    }

    public function test_a_period_over_a_year_is_refused(): void
    {
        $this->actingAs($this->technologist)
            ->getJson(self::URL.'?from=2024-01-01&to=2025-06-01')
            ->assertStatus(422)
            ->assertJsonPath('code', 'period_too_long');
    }
}
