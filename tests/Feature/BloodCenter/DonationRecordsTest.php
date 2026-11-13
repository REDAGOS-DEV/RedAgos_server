<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\DonationScreening;
use App\Models\Facility;
use App\Models\User;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The Donations list (project owner, 2026-10-17).
 *
 * What is locked in: a visit left open is visible and can be closed from the
 * list, the list holds every centre's donations with another centre's read
 * only (project owner, 2026-11-13), the export is this centre's alone, and a
 * closed visit says why only as far as the viewer may know.
 */
class DonationRecordsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $desk;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->desk = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
    }

    private function visit(DonationStatus $status, int $daysAgo = 0, ?Facility $at = null): Donation
    {
        return Donation::factory()->create([
            'donor_id' => User::factory()->donor()->create()->id,
            'facility_id' => ($at ?? $this->facility)->id,
            'donation_date' => Carbon::parse(OperationalDay::today()->subDays($daysAgo)->toDateString().' 10:00'),
            'status' => $status,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(User $viewer, array $query = []): array
    {
        return $this->actingAs($viewer)
            ->getJson('/api/blood-center/donation-records?'.http_build_query($query))
            ->assertOk()
            ->json('data');
    }

    public function test_the_list_is_newest_first_with_open_visits_marked(): void
    {
        $older = $this->visit(DonationStatus::Completed, 3);
        $open = $this->visit(DonationStatus::Registered);

        $rows = $this->rows($this->desk);

        $this->assertSame([$open->id, $older->id], array_column($rows, 'id'));
        $this->assertSame('own_facility', $rows[0]['scope']);
        $this->assertSame($this->facility->id, $rows[0]['centre']['id']);
        $this->assertSame('DON-'.str_pad((string) $open->id, 6, '0', STR_PAD_LEFT), $rows[0]['code']);
        $this->assertTrue($rows[0]['open']);
        $this->assertTrue($rows[0]['can']['close']);
        $this->assertFalse($rows[1]['open']);
        $this->assertSame('10:00', $rows[0]['time']);

        // Recorded at the counter, not typed from a drive's paper sheet.
        $this->assertSame('counter', $rows[0]['source']);
        $this->assertNull($rows[0]['paper_sheet']);
    }

    public function test_another_centres_donations_are_listed_read_only(): void
    {
        $other = Facility::factory()->approved()->create(['abbreviation' => 'SPMC']);
        $given = $this->visit(DonationStatus::Completed, 1, at: $other);
        $given->donorProfile->donor->forceFill(['first_name' => 'Kai', 'last_name' => 'Licong'])->save();
        $open = $this->visit(DonationStatus::Registered, at: $other);
        $deferred = $this->visit(DonationStatus::Rejected, 2, at: $other);
        DonationScreening::factory()->deferred()->create(['donation_id' => $deferred->id, 'facility_id' => $other->id]);
        $here = $this->visit(DonationStatus::Completed, 3);

        $rows = collect($this->rows($this->desk))->keyBy('id');

        $this->assertEqualsCanonicalizing([$given->id, $open->id, $deferred->id, $here->id], $rows->keys()->all());
        $this->assertSame('cross_facility', $rows[$given->id]['scope']);
        $this->assertSame(['id' => $other->id, 'name' => $other->name, 'abbreviation' => 'SPMC'], $rows[$given->id]['centre']);
        $this->assertSame('own_facility', $rows[$here->id]['scope']);

        // Found by name at the next centre.
        $this->assertSame([$given->id], array_column($this->rows($this->desk, ['search' => 'kai']), 'id'));

        // Not closed from here: the visit is the other centre's.
        $this->assertTrue($rows[$open->id]['open']);
        $this->assertFalse($rows[$open->id]['can']['close']);
        $this->actingAs($this->desk)
            ->patchJson("/api/blood-center/donations/{$open->id}/status", ['status' => 'rejected'])
            ->assertNotFound();

        // The deferral's code shows; its reason stays with the centre that recorded it.
        $physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $clinical = collect($this->rows($physician))->keyBy('id');
        $this->assertSame('Deferred', $clinical[$deferred->id]['status_label']);
        $this->assertNull($clinical[$deferred->id]['screening']['deferral_reason']);
    }

    public function test_it_filters_open_visits_and_searches_donor_code_and_bag(): void
    {
        $open = $this->visit(DonationStatus::Screening);
        $drawn = $this->visit(DonationStatus::Completed, 1);
        $drawn->collection->forceFill(['donation_barcode' => 'RA1-0099'])->save();
        $open->donorProfile->donor->forceFill(['first_name' => 'Rafael', 'last_name' => 'Ababa'])->save();

        $ids = fn (array $q) => array_column($this->rows($this->desk, $q), 'id');

        $this->assertSame([$open->id], $ids(['status' => 'open']));
        $this->assertSame([$open->id], $ids(['search' => 'rafael ababa']));
        $this->assertSame([$drawn->id], $ids(['search' => 'RA1-0099']));
        $this->assertSame([$drawn->id], $ids(['search' => 'DON-'.str_pad((string) $drawn->id, 6, '0', STR_PAD_LEFT)]));
        $this->assertSame([$open->id], $ids(['from' => OperationalDay::todayAsDate(), 'to' => OperationalDay::todayAsDate()]));
    }

    public function test_a_scanned_sticker_or_a_full_bag_number_finds_its_donation(): void
    {
        $this->facility->forceFill(['abbreviation' => 'SNBC'])->save();

        $drawn = $this->visit(DonationStatus::Completed, 1);
        $drawn->collection->forceFill(['donation_barcode' => '21627990'])->save();
        $this->visit(DonationStatus::Completed, 2)->collection->forceFill(['donation_barcode' => '31415926'])->save();

        $ids = fn (array $q) => array_column($this->rows($this->desk, $q), 'id');

        $this->assertSame([$drawn->id], $ids(['search' => '21627990']));
        $this->assertSame([$drawn->id], $ids(['search' => 'SNBC-21627990-PRBC']));
        $this->assertSame([$drawn->id], $ids(['search' => 'snbc-21627990-ffp-2']));

        // The same sticker at another centre: its bag number finds that
        // centre's donation, the sticker alone finds both.
        $prc = Facility::factory()->approved()->create(['abbreviation' => 'PRC']);
        $there = $this->visit(DonationStatus::Completed, 3, at: $prc);
        $there->collection->forceFill(['donation_barcode' => '21627990'])->save();

        $this->assertSame([$there->id], $ids(['search' => 'PRC-21627990-PRBC']));
        $this->assertSame([$drawn->id], $ids(['search' => 'SNBC-21627990-PRBC']));
        $this->assertSame([$drawn->id, $there->id], $ids(['search' => '21627990']));
    }

    public function test_an_open_visit_is_closed_from_the_list_through_the_counters_route(): void
    {
        $open = $this->visit(DonationStatus::Registered);

        $this->actingAs($this->desk)
            ->patchJson("/api/blood-center/donations/{$open->id}/status", ['status' => 'rejected', 'rejection_reason' => 'Left before screening.'])
            ->assertOk();

        $row = $this->rows($this->desk)[0];
        $this->assertFalse($row['open']);
        $this->assertSame('Closed', $row['status_label']);
    }

    public function test_a_closed_visit_says_why_only_as_far_as_the_viewer_may_know(): void
    {
        $deferred = $this->visit(DonationStatus::Rejected, 2);
        DonationScreening::factory()->deferred()->create(['donation_id' => $deferred->id, 'facility_id' => $this->facility->id]);

        $reactive = $this->visit(DonationStatus::Rejected, 1);
        CounsellingReferral::create(['donation_id' => $reactive->id, 'donor_id' => $reactive->donor_id, 'facility_id' => $this->facility->id]);

        $desk = collect($this->rows($this->desk))->keyBy('id');
        $this->assertSame('Deferred', $desk[$deferred->id]['status_label']);
        $this->assertNull($desk[$deferred->id]['screening']['deferral_reason']);
        $this->assertSame('Closed', $desk[$reactive->id]['status_label']);

        $physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $clinical = collect($this->rows($physician))->keyBy('id');
        $this->assertSame('Rejected after testing', $clinical[$reactive->id]['status_label']);
        $this->assertNotNull($clinical[$deferred->id]['screening']['deferral_reason']);
    }

    public function test_only_the_counter_reads_it(): void
    {
        $this->actingAs(User::factory()->bloodCenterStaff($this->facility, StaffRole::BillingClerk)->create())
            ->getJson('/api/blood-center/donation-records')
            ->assertForbidden();
    }

    public function test_the_export_is_this_centres_filtered_list_as_csv(): void
    {
        $here = $this->visit(DonationStatus::Completed, 1);
        $there = $this->visit(DonationStatus::Completed, 1, at: Facility::factory()->approved()->create());

        $csv = $this->actingAs($this->desk)->get('/api/blood-center/donation-records/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('DON-'.str_pad((string) $here->id, 6, '0', STR_PAD_LEFT), $csv);
        $this->assertStringNotContainsString('DON-'.str_pad((string) $there->id, 6, '0', STR_PAD_LEFT), $csv);

        $this->assertStringStartsWith("\xEF\xBB\xBFDonationID,Donor,DonorID,BloodGroup,Date,Time,Status", $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'donations.exported', 'actor_id' => $this->desk->id]);
    }
}
