<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\BloodCollection;
use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Processing's Processed list (project owner, 2026-10-19).
 *
 * What is locked in: the list is this centre's donations with a recorded
 * breakdown, and Component Processing's queue no longer holds them; each row
 * says whether it may still be handed over or rejected, and only to the role
 * that may; the hand-over made from it moves the row along; and Processing
 * stays blind.
 */
class ProcessedDonationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $technologist;

    private User $assistant;

    private BloodComponent $component;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->technologist = User::factory()->bloodCenterStaff($this->facility, StaffRole::ComponentTechnologist)->create();
        $this->assistant = User::factory()->bloodCenterStaff($this->facility, StaffRole::ProcessingAssistant)->create();
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC']);
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

    private function process(Donation $donation, ?User $as = null, array $volumes = [250]): TestResponse
    {
        return $this->actingAs($as ?? $this->assistant)->postJson(
            "/api/blood-center/laboratory/donations/{$donation->id}/components",
            ['components' => array_map(fn (int $ml): array => ['component_id' => $this->component->id, 'volume_ml' => $ml], $volumes)]
        )->assertCreated();
    }

    private function handOver(Donation $donation): TestResponse
    {
        return $this->actingAs($this->technologist)->patchJson(
            "/api/blood-center/laboratory/donations/{$donation->id}/status",
            ['status' => 'completed']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(User $viewer, array $query = []): array
    {
        return $this->actingAs($viewer)
            ->getJson('/api/blood-center/laboratory/processed?'.http_build_query($query))
            ->assertOk()
            ->json('data');
    }

    public function test_the_list_is_this_centres_processed_donations_newest_first(): void
    {
        $first = $this->drawn('BC-0001');
        $second = $this->drawn('BC-0002');
        $unprocessed = $this->drawn('BC-0003');
        $elsewhere = $this->drawn('BC-9999', at: Facility::factory()->approved()->create());

        Carbon::setTestNow(now()->subHour());
        $this->process($first);
        Carbon::setTestNow();
        $this->process($second, volumes: [250, 230]);

        $rows = $this->rows($this->technologist);

        $this->assertSame([$second->id, $first->id], array_column($rows, 'donation_id'));
        $this->assertSame('DON-'.str_pad((string) $second->id, 6, '0', STR_PAD_LEFT), $rows[0]['code']);
        $this->assertSame('BC-0002', $rows[0]['bag']['barcode']);
        $this->assertSame(2, $rows[0]['bag_count']);
        $this->assertSame(480, $rows[0]['processed_volume_ml']);
        $this->assertSame(['Packed RBC', 'Packed RBC'], array_column($rows[0]['components'], 'component'));
        // The code on the bag's label, for the list's chips.
        $this->assertSame([$this->component->labelCode(), $this->component->labelCode()], array_column($rows[0]['components'], 'code'));
        $this->assertSame(trim($this->assistant->first_name.' '.$this->assistant->last_name), $rows[0]['processed_by']);
        $this->assertSame('awaiting_handover', $rows[0]['status']);
        $this->assertSame(['tti' => false, 'immunohematology' => false], $rows[0]['clearances']);
        $this->assertNotContains($unprocessed->id, array_column($rows, 'donation_id'));
        $this->assertNotContains($elsewhere->id, array_column($rows, 'donation_id'));

        $this->assertSame([$first->id, $second->id], array_column($this->rows($this->technologist, ['sort' => 'oldest']), 'donation_id'));
    }

    public function test_a_processed_unit_leaves_the_component_processing_queue(): void
    {
        $waiting = $this->drawn('BC-1001');
        $processed = $this->drawn('BC-1002');
        $this->process($processed);

        $queue = $this->actingAs($this->technologist)
            ->getJson('/api/blood-center/laboratory/queue?stage=processing')
            ->assertOk()
            ->json('data');

        $this->assertSame([$waiting->id], array_column($queue, 'id'));

        // Without a stage the laboratory's queue is unchanged.
        $all = $this->actingAs($this->technologist)->getJson('/api/blood-center/laboratory/queue')->json('data');
        $this->assertEqualsCanonicalizing([$waiting->id, $processed->id], array_column($all, 'id'));
    }

    public function test_only_the_component_technologist_is_offered_the_hand_over(): void
    {
        $donation = $this->drawn('BC-2001');
        $this->process($donation);

        $mine = $this->rows($this->technologist)[0];
        $this->assertTrue($mine['can']['hand_over']);
        $this->assertTrue($mine['can']['reject']);

        // The assistant records breakdowns but hands nothing over.
        $theirs = $this->rows($this->assistant)[0];
        $this->assertFalse($theirs['can']['hand_over']);
        $this->assertFalse($theirs['can']['reject']);

        $this->actingAs($this->assistant)
            ->patchJson("/api/blood-center/laboratory/donations/{$donation->id}/status", ['status' => 'completed'])
            ->assertForbidden();
    }

    public function test_the_hand_over_moves_the_row_along_and_closes_it(): void
    {
        $handed = $this->drawn('BC-3001');
        $rejected = $this->drawn('BC-3002');
        $waiting = $this->drawn('BC-3003');

        foreach ([$handed, $rejected, $waiting] as $donation) {
            $this->process($donation);
        }

        $this->handOver($handed)->assertOk()->assertJsonPath('data.status', 'completed');
        $this->actingAs($this->technologist)->patchJson("/api/blood-center/laboratory/donations/{$rejected->id}/status", [
            'status' => 'rejected',
            'rejection_reason' => 'Clotted during separation.',
        ])->assertOk();

        BloodUnit::factory()->create(['facility_id' => $this->facility->id, 'donation_id' => $handed->id]);

        $rows = collect($this->rows($this->technologist))->keyBy('donation_id');

        $this->assertSame('handed_over', $rows[$handed->id]['status']);
        $this->assertSame('Handed over', $rows[$handed->id]['status_label']);
        $this->assertSame(1, $rows[$handed->id]['units_booked']);
        $this->assertFalse($rows[$handed->id]['can']['hand_over']);
        $this->assertFalse($rows[$handed->id]['can']['reject']);

        $this->assertSame('rejected', $rows[$rejected->id]['status']);
        $this->assertSame('Clotted during separation.', $rows[$rejected->id]['rejection_reason']);
        $this->assertFalse($rows[$rejected->id]['can']['hand_over']);

        $this->assertSame([$waiting->id], array_column($this->rows($this->technologist, ['status' => 'awaiting_handover']), 'donation_id'));
        $this->assertSame([$handed->id], array_column($this->rows($this->technologist, ['status' => 'handed_over']), 'donation_id'));
        $this->assertSame([$rejected->id], array_column($this->rows($this->technologist, ['status' => 'rejected']), 'donation_id'));

        // The server still refuses a second hand-over.
        $this->handOver($handed)->assertStatus(409)->assertJsonPath('code', 'donation_already_final');
    }

    private function fileCorrection(Donation $donation, User $as, array $volumes = [250, 200]): TestResponse
    {
        return $this->actingAs($as)->postJson("/api/blood-center/donations/{$donation->id}/corrections", [
            'subject' => 'components',
            'reason' => 'The second bag was not recorded.',
            'changes' => ['components' => array_map(
                fn (int $ml): array => ['component_id' => $this->component->id, 'volume_ml' => $ml],
                $volumes
            )],
        ]);
    }

    public function test_each_row_says_whether_its_breakdown_can_still_be_corrected(): void
    {
        $open = $this->drawn('BC-4001');
        $handed = $this->drawn('BC-4002');
        $rejected = $this->drawn('BC-4003');

        foreach ([$open, $handed, $rejected] as $donation) {
            $this->process($donation);
        }

        $this->handOver($handed)->assertOk();
        $this->actingAs($this->technologist)->patchJson("/api/blood-center/laboratory/donations/{$rejected->id}/status", [
            'status' => 'rejected',
            'rejection_reason' => 'Clotted during separation.',
        ])->assertOk();

        foreach ([$this->assistant, $this->technologist] as $viewer) {
            $rows = collect($this->rows($viewer))->keyBy('donation_id');

            $this->assertTrue($rows[$open->id]['can']['correct']);
            $this->assertNull($rows[$open->id]['locked']);
            $this->assertFalse($rows[$handed->id]['can']['correct']);
            $this->assertSame('handed_over', $rows[$handed->id]['locked']);
            $this->assertFalse($rows[$rejected->id]['can']['correct']);
            $this->assertSame('rejected', $rows[$rejected->id]['locked']);
        }

        // The correction route agrees: a handed-over breakdown is fixed.
        $this->fileCorrection($handed, $this->assistant)->assertStatus(409)->assertJsonPath('code', 'not_correctable');
    }

    public function test_the_component_technologist_decides_an_assistants_correction_from_the_list(): void
    {
        $donation = $this->drawn('BC-4501');
        $this->process($donation);

        $this->fileCorrection($donation, $this->assistant)->assertCreated();

        // The assistant sees it waiting, and may neither decide it nor file another.
        $mine = $this->rows($this->assistant)[0];
        $this->assertTrue($mine['correction_pending']);
        $this->assertFalse($mine['can']['correct']);
        $this->assertSame('The second bag was not recorded.', $mine['correction']['reason']);
        $this->assertSame(trim($this->assistant->first_name.' '.$this->assistant->last_name), $mine['correction']['requested_by']);
        $this->assertSame([250, 200], array_column($mine['correction']['components'], 'volume_ml'));
        $this->assertSame(['Packed RBC', 'Packed RBC'], array_column($mine['correction']['components'], 'component'));
        $this->assertFalse($mine['correction']['can_decide']);

        $row = $this->rows($this->technologist, ['status' => 'correction_pending'])[0];
        $this->assertSame($donation->id, $row['donation_id']);
        $this->assertTrue($row['correction']['can_decide']);

        $this->actingAs($this->technologist)->postJson("/api/blood-center/corrections/{$row['correction']['id']}/approve")->assertOk();

        $after = $this->rows($this->technologist)[0];
        $this->assertNull($after['correction']);
        $this->assertSame(2, $after['bag_count']);
        $this->assertSame(450, $after['processed_volume_ml']);
        $this->assertTrue($after['can']['correct']);
        $this->assertSame([], $this->rows($this->technologist, ['status' => 'correction_pending']));
    }

    public function test_the_component_technologists_own_correction_applies_as_it_is_filed(): void
    {
        $donation = $this->drawn('BC-5101');
        $this->process($donation);

        $this->fileCorrection($donation, $this->technologist, [240])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved');

        $row = $this->rows($this->technologist)[0];
        $this->assertNull($row['correction']);
        $this->assertSame(240, $row['processed_volume_ml']);
    }

    public function test_it_finds_a_bag_a_bag_number_or_a_donation_but_never_a_name(): void
    {
        $donation = $this->drawn('BC-5001', 'Maria');
        $other = $this->drawn('BC-6002');
        $this->process($donation);
        $this->process($other);

        $this->assertSame([$donation->id], array_column($this->rows($this->technologist, ['search' => 'bc-50']), 'donation_id'));
        $this->assertSame([$donation->id], array_column($this->rows($this->technologist, ['search' => 'DON-'.$donation->id]), 'donation_id'));

        $bagNumber = $this->rows($this->technologist, ['search' => 'BC-5001'])[0]['components'][0]['bag_number'];
        $this->assertNotNull($bagNumber);
        $this->assertSame([$donation->id], array_column($this->rows($this->technologist, ['search' => $bagNumber]), 'donation_id'));

        // Processing works blind: no name, and no finding a unit by one.
        $donor = $this->rows($this->technologist, ['search' => 'BC-5001'])[0]['donor'];
        $this->assertTrue($donor['blinded']);
        $this->assertArrayNotHasKey('full_name', $donor);
        $this->assertSame([], $this->rows($this->technologist, ['search' => 'Maria']));
    }

    public function test_only_processing_reads_it(): void
    {
        $this->actingAs(User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create())
            ->getJson('/api/blood-center/laboratory/processed')
            ->assertForbidden();

        $this->actingAs($this->technologist)
            ->getJson('/api/blood-center/laboratory/processed?status=everything')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_the_export_is_the_filtered_list_as_csv_and_is_audited(): void
    {
        $this->process($this->drawn('BC-7001', 'Pedro'));

        $csv = $this->actingAs($this->technologist)
            ->get('/api/blood-center/laboratory/processed/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBFDonationID,Bag,Donor,DonorID,BloodGroup,Components,Bags,ProcessedVolumeMl", $csv);
        $this->assertStringContainsString('BC-7001', $csv);
        $this->assertStringContainsString('Awaiting hand-over', $csv);
        $this->assertStringNotContainsString('Pedro', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.processed_exported', 'actor_id' => $this->technologist->id]);
    }
}
