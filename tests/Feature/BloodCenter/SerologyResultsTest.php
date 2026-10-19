<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\BloodCollection;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TTI Testing's results list (project owner, 2026-10-18).
 *
 * What is locked in: the list is this centre's panels, marker by marker, to
 * TTI Testing alone; a blind technologist is told no name and cannot find a
 * panel by one; and each row says whether it can still be corrected, for the
 * reasons the correction route itself would refuse.
 */
class SerologyResultsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $technologist;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->technologist = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $this->supervisor = User::factory()->bloodCenterStaff($this->facility, StaffRole::LabSupervisor)->create();
    }

    private function drawn(string $barcode, string $firstName = 'Juan', ?Facility $at = null): Donation
    {
        $donor = User::factory()->donor()->create(['first_name' => $firstName, 'last_name' => 'Dela Cruz']);

        $donation = Donation::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => ($at ?? $this->facility)->id,
            'status' => DonationStatus::Collected,
        ]);

        BloodCollection::where('donation_id', $donation->id)->update(['donation_barcode' => $barcode]);

        return $donation;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(Donation $donation, array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->technologist)->postJson(
            "/api/blood-center/laboratory/donations/{$donation->id}/serology",
            [
                'hiv' => 'non_reactive',
                'hbsag' => 'non_reactive',
                'hcv' => 'non_reactive',
                'syphilis' => 'non_reactive',
                'malaria' => 'non_reactive',
                ...$overrides,
            ]
        )->assertCreated();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(User $viewer, array $query = []): array
    {
        return $this->actingAs($viewer)
            ->getJson('/api/blood-center/laboratory/serology-results?'.http_build_query($query))
            ->assertOk()
            ->json('data');
    }

    public function test_the_list_is_this_centres_panels_newest_first_marker_by_marker(): void
    {
        $first = $this->drawn('BC-0001');
        $second = $this->drawn('BC-0002');
        $elsewhere = $this->drawn('BC-9999', at: Facility::factory()->approved()->create());
        $untested = $this->drawn('BC-0003');

        Carbon::setTestNow(now()->subHour());
        $this->record($first);
        Carbon::setTestNow();
        $this->record($second, ['hbsag' => 'reactive', 'confirm_reactive' => true]);

        $rows = $this->rows($this->technologist);

        $this->assertSame([$second->id, $first->id], array_column($rows, 'donation_id'));
        $this->assertSame('DON-'.str_pad((string) $second->id, 6, '0', STR_PAD_LEFT), $rows[0]['code']);
        $this->assertSame('BC-0002', $rows[0]['bag']['barcode']);
        $this->assertSame('reactive', $rows[0]['outcome']);
        $this->assertSame(['hiv', 'hbsag', 'hcv', 'syphilis', 'malaria'], array_column($rows[0]['markers'], 'marker'));
        $this->assertSame('reactive', $rows[0]['markers'][1]['result']);
        $this->assertSame('non_reactive', $rows[1]['outcome']);
        $this->assertSame(trim($this->technologist->first_name.' '.$this->technologist->last_name), $rows[1]['recorded_by']);
        $this->assertNotContains($elsewhere->id, array_column($rows, 'donation_id'));
        $this->assertNotContains($untested->id, array_column($rows, 'donation_id'));
    }

    public function test_it_filters_by_outcome_sorts_reactive_first_and_finds_a_bag_or_donation(): void
    {
        $clear = $this->drawn('BC-1001');
        $reactive = $this->drawn('BC-2002');

        Carbon::setTestNow(now()->subHour());
        $this->record($reactive, ['malaria' => 'reactive', 'confirm_reactive' => true]);
        Carbon::setTestNow();
        $this->record($clear);

        $this->assertSame([$reactive->id], array_column($this->rows($this->technologist, ['outcome' => 'reactive']), 'donation_id'));
        $this->assertSame([$clear->id], array_column($this->rows($this->technologist, ['outcome' => 'non_reactive']), 'donation_id'));
        $this->assertSame([$reactive->id, $clear->id], array_column($this->rows($this->technologist, ['sort' => 'reactive_first']), 'donation_id'));
        $this->assertSame([$reactive->id, $clear->id], array_column($this->rows($this->technologist, ['sort' => 'oldest']), 'donation_id'));

        $this->assertSame([$clear->id], array_column($this->rows($this->technologist, ['search' => 'bc-10']), 'donation_id'));
        $this->assertSame([$reactive->id], array_column($this->rows($this->technologist, ['search' => 'DON-'.$reactive->id]), 'donation_id'));
    }

    public function test_a_blind_technologist_is_told_no_name_and_cannot_search_by_one(): void
    {
        $donation = $this->drawn('BC-3003', 'Maria');
        $this->record($donation);

        $blind = $this->rows($this->technologist)[0]['donor'];
        $this->assertTrue($blind['blinded']);
        $this->assertArrayNotHasKey('full_name', $blind);
        $this->assertSame([], $this->rows($this->technologist, ['search' => 'Maria']));

        // The supervisor, who works the referral list, holds the identity.
        $this->assertSame('Maria Dela Cruz', $this->rows($this->supervisor)[0]['donor']['full_name']);
        $this->assertSame([$donation->id], array_column($this->rows($this->supervisor, ['search' => 'maria']), 'donation_id'));
    }

    public function test_each_row_says_whether_it_can_still_be_corrected(): void
    {
        $open = $this->drawn('BC-4001');
        $reactive = $this->drawn('BC-4002');
        $released = $this->drawn('BC-4003');
        $pending = $this->drawn('BC-4004');
        $awaiting = $this->drawn('BC-4005');

        $this->record($open);
        $this->record($reactive, ['hiv' => 'reactive', 'confirm_reactive' => true], $this->supervisor);
        $this->record($released);
        $this->record($pending);
        $this->record($awaiting, ['hcv' => 'reactive', 'confirm_reactive' => true]);

        BloodUnit::factory()->create([
            'facility_id' => $this->facility->id,
            'donation_id' => $released->id,
            'status' => BloodUnitStatus::Available,
        ]);

        $this->actingAs($this->technologist)->postJson("/api/blood-center/donations/{$pending->id}/corrections", [
            'subject' => 'serology',
            'reason' => 'Malaria was read from the wrong strip.',
            'changes' => ['hiv' => 'non_reactive', 'hbsag' => 'non_reactive', 'hcv' => 'non_reactive', 'syphilis' => 'non_reactive', 'malaria' => 'non_reactive'],
        ])->assertCreated();

        $rows = collect($this->rows($this->technologist))->keyBy('donation_id');

        $this->assertTrue($rows[$open->id]['can']['correct']);
        $this->assertNull($rows[$open->id]['locked']);

        $this->assertFalse($rows[$reactive->id]['can']['correct']);
        $this->assertSame('reactive', $rows[$reactive->id]['locked']);

        $this->assertFalse($rows[$released->id]['can']['correct']);
        $this->assertSame('units_released', $rows[$released->id]['locked']);

        $this->assertFalse($rows[$pending->id]['can']['correct']);
        $this->assertTrue($rows[$pending->id]['correction_pending']);
        $this->assertNull($rows[$pending->id]['locked']);

        // A technologist's reactive panel waits for the supervisor, who alone
        // is offered Verify and Return.
        $this->assertTrue($rows[$awaiting->id]['awaiting_verification']);
        $this->assertSame('awaiting_verification', $rows[$awaiting->id]['locked']);
        $this->assertFalse($rows[$awaiting->id]['can']['verify']);
        $this->assertFalse($rows[$reactive->id]['awaiting_verification']);

        $forSupervisor = collect($this->rows($this->supervisor, ['outcome' => 'awaiting_verification']));
        $this->assertSame([$awaiting->id], $forSupervisor->pluck('donation_id')->all());
        $this->assertTrue($forSupervisor->first()['can']['verify']);
    }

    public function test_the_supervisor_decides_a_pending_correction_from_the_list(): void
    {
        $donation = $this->drawn('BC-6001');
        $this->record($donation);

        $this->actingAs($this->technologist)->postJson("/api/blood-center/donations/{$donation->id}/corrections", [
            'subject' => 'serology',
            'reason' => 'Malaria was read from the wrong strip.',
            'changes' => ['hiv' => 'non_reactive', 'hbsag' => 'non_reactive', 'hcv' => 'non_reactive', 'syphilis' => 'non_reactive', 'malaria' => 'reactive', 'confirm_reactive' => true],
        ])->assertCreated();

        // The technologist who asked sees it waiting, and may not decide it.
        $mine = $this->rows($this->technologist)[0]['correction'];
        $this->assertSame('Malaria was read from the wrong strip.', $mine['reason']);
        $this->assertSame('reactive', $mine['changes']['malaria']);
        $this->assertArrayNotHasKey('confirm_reactive', $mine['changes']);
        $this->assertFalse($mine['can_decide']);

        $row = $this->rows($this->supervisor, ['outcome' => 'correction_pending'])[0];
        $this->assertSame($donation->id, $row['donation_id']);
        $this->assertTrue($row['correction']['can_decide']);

        $this->actingAs($this->supervisor)->postJson("/api/blood-center/corrections/{$row['correction']['id']}/approve")->assertOk();

        // Approved, the correction is the verification: the panel is reactive and acted.
        $after = $this->rows($this->supervisor)[0];
        $this->assertNull($after['correction']);
        $this->assertSame('reactive', $after['outcome']);
        $this->assertFalse($after['awaiting_verification']);
        $this->assertSame([], $this->rows($this->supervisor, ['outcome' => 'correction_pending']));
    }

    public function test_only_tti_testing_reads_it(): void
    {
        // Processing holds lab.view, but the readings are not theirs.
        $this->actingAs(User::factory()->bloodCenterStaff($this->facility, StaffRole::ComponentTechnologist)->create())
            ->getJson('/api/blood-center/laboratory/serology-results')
            ->assertForbidden();
    }

    public function test_the_export_is_the_filtered_list_as_csv_and_is_audited(): void
    {
        $this->record($this->drawn('BC-5001', 'Pedro'));

        $csv = $this->actingAs($this->technologist)
            ->get('/api/blood-center/laboratory/serology-results/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBFDonationID,Bag,Donor,DonorID,BloodGroup,HIV,HBsAg,HCV,Syphilis,Malaria,Overall,TestedAt,TestedBy,DonationStatus", $csv);
        $this->assertStringContainsString('BC-5001', $csv);
        $this->assertStringNotContainsString('Pedro', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.serology_exported', 'actor_id' => $this->technologist->id]);
    }
}
