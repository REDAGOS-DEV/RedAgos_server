<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\DonationStatus;
use App\Enums\DonationType;
use App\Enums\ScreeningOutcome;
use App\Enums\StaffRole;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonationScreening;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Donor & Collection department's reports for a period.
 */
class CollectionReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $centre;

    private User $physician;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', config('blood_center.timezone')));
        $this->centre = Facility::factory()->approved()->create();
        $this->physician = User::factory()->bloodCenterStaff($this->centre, StaffRole::ScreeningPhysician)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function report(): array
    {
        return $this->actingAs($this->physician)
            ->getJson('/api/blood-center/reports/collection?from=2026-10-01&to=2026-10-20')
            ->assertOk()
            ->json();
    }

    /**
     * One part of an .xlsx, read through the zip's central directory without
     * ext-zip, which is not on every PHP build. Checks the part's CRC, so a
     * malformed archive fails here rather than in Excel.
     */
    private function xlsxPart(string $bytes, string $name): string
    {
        $end = strrpos($bytes, "PK\x05\x06");
        $this->assertNotFalse($end, 'The workbook has no zip directory.');
        $directory = unpack('ventries/Vsize/Voffset', substr($bytes, $end + 10, 10));

        for ($i = 0, $at = $directory['offset']; $i < $directory['entries']; $i++) {
            $entry = unpack('Vsignature/x6/vmethod/x4/Vcrc/Vcompressed/x4/vname/vextra/vcomment/x8/Vlocal', substr($bytes, $at, 46));
            $this->assertSame(0x02014B50, $entry['signature']);
            $entryName = substr($bytes, $at + 46, $entry['name']);
            $at += 46 + $entry['name'] + $entry['extra'] + $entry['comment'];

            if ($entryName !== $name) {
                continue;
            }

            $local = unpack('Vsignature/x22/vname/vextra', substr($bytes, $entry['local'], 30));
            $this->assertSame(0x04034B50, $local['signature']);
            $data = substr($bytes, $entry['local'] + 30 + $local['name'] + $local['extra'], $entry['compressed']);
            $contents = $entry['method'] === 8 ? gzinflate($data) : $data;
            $this->assertIsString($contents);
            $this->assertSame($entry['crc'], crc32($contents));

            return $contents;
        }

        $this->fail("The workbook has no {$name}.");
    }

    public function test_it_counts_donations_and_deferrals_with_their_reasons(): void
    {
        Donation::factory()->count(3)->create(['facility_id' => $this->centre->id, 'donation_date' => '2026-10-05 09:00', 'status' => DonationStatus::Completed]);
        $deferred = Donation::factory()->create(['facility_id' => $this->centre->id, 'donation_date' => '2026-10-06 09:00', 'status' => DonationStatus::Rejected]);
        DonationScreening::factory()->create([
            'donation_id' => $deferred->id,
            'facility_id' => $this->centre->id,
            'outcome' => ScreeningOutcome::TemporarilyDeferred,
            'deferral_reason' => 'Low hemoglobin',
        ]);

        $data = $this->report();

        $this->assertSame(3, $data['donations']['collected']);
        $this->assertSame(1, $data['donations']['deferred']);
        $this->assertEquals(25.0, $data['donations']['deferral_rate']);
        $this->assertSame('Low hemoglobin', $data['deferrals']['top_reasons'][0]['reason']);
        $this->assertSame('Temporarily Deferred', $data['deferrals']['by_type'][0]['type']);
        $this->assertSame(3, collect($data['daily'])->firstWhere('date', '2026-10-05')['donations']);
    }

    public function test_it_counts_the_appointments_and_the_show_rate(): void
    {
        DonationAppointment::factory()->create(['facility_id' => $this->centre->id, 'appointment_datetime' => '2026-10-08 09:00', 'status' => AppointmentStatus::Completed]);
        DonationAppointment::factory()->create(['facility_id' => $this->centre->id, 'appointment_datetime' => '2026-10-08 10:00', 'status' => AppointmentStatus::NoShow]);
        DonationAppointment::factory()->create(['facility_id' => $this->centre->id, 'appointment_datetime' => '2026-09-08 10:00', 'status' => AppointmentStatus::Completed]);

        $appointments = $this->report()['appointments'];

        $this->assertSame(2, $appointments['booked']);
        $this->assertSame(1, $appointments['no_show']);
        $this->assertEquals(50.0, $appointments['show_rate']);
    }

    public function test_the_donor_profile_counts_sex_age_and_type_of_donor(): void
    {
        $given = ['status' => DonationStatus::Completed];
        $elsewhere = Facility::factory()->approved()->create();

        // First time: a woman of 20, never donated.
        $first = DonorProfile::factory()->create(['gender' => 'female', 'birth_date' => '2006-01-15']);
        Donation::factory()->create($given + ['donor_id' => $first->donor_id, 'facility_id' => $this->centre->id, 'donation_date' => '2026-10-05 09:00']);

        // New to the centre: a man of 30 who gave at another centre.
        $newHere = DonorProfile::factory()->create(['gender' => 'male', 'birth_date' => '1996-03-01']);
        Donation::factory()->create($given + ['donor_id' => $newHere->donor_id, 'facility_id' => $elsewhere->id, 'donation_date' => '2026-05-01 09:00']);
        Donation::factory()->create($given + ['donor_id' => $newHere->donor_id, 'facility_id' => $this->centre->id, 'donation_date' => '2026-10-06 09:00']);

        // Repeat: gave here four months before.
        $repeat = DonorProfile::factory()->create(['gender' => 'male', 'birth_date' => '1976-03-01']);
        Donation::factory()->create($given + ['donor_id' => $repeat->donor_id, 'facility_id' => $this->centre->id, 'donation_date' => '2026-06-01 09:00']);
        Donation::factory()->create($given + ['donor_id' => $repeat->donor_id, 'facility_id' => $this->centre->id, 'donation_date' => '2026-10-07 09:00']);

        // Lapsed: last gave here two years before.
        $lapsed = DonorProfile::factory()->create(['gender' => 'female', 'birth_date' => '2009-01-01']);
        Donation::factory()->create($given + ['donor_id' => $lapsed->donor_id, 'facility_id' => $this->centre->id, 'donation_date' => '2024-09-01 09:00']);
        Donation::factory()->create($given + ['donor_id' => $lapsed->donor_id, 'facility_id' => $this->centre->id, 'donation_date' => '2026-10-08 09:00']);

        $profile = $this->report()['donor_profile'];
        $total = $profile['total'];

        $this->assertSame(4, $total['total']);
        $this->assertSame(['female' => 2, 'male' => 2, 'not_stated' => 0], $total['sex']);
        $this->assertSame(1, $total['age']['16-17']);
        $this->assertSame(1, $total['age']['18-24']);
        $this->assertSame(1, $total['age']['25-44']);
        $this->assertSame(1, $total['age']['45-64']);
        $this->assertSame(['new_to_centre' => 1, 'first_time' => 1, 'repeat_retained' => 1, 'lapsed' => 1], $total['type']);
        $this->assertSame(4, $total['kind']['walk_in']);
        $this->assertSame('2026-10', $profile['months'][0]['month']);
    }

    public function test_it_compares_with_the_period_before_and_maps_the_busiest_hours(): void
    {
        $given = ['facility_id' => $this->centre->id, 'status' => DonationStatus::Completed];
        // Monday 5 October 2026, 9:15 in Manila (01:15 UTC), twice; and one in the period before.
        Donation::factory()->count(2)->create($given + ['donation_date' => '2026-10-05 01:15']);
        Donation::factory()->create($given + ['donation_date' => '2026-09-20 10:00']);

        $data = $this->report();

        $this->assertSame('2026-09-11', $data['previous']['from']);
        $this->assertSame('2026-09-30', $data['previous']['to']);
        $this->assertSame(1, $data['previous']['collected']);
        $this->assertSame(2, $data['busiest'][0][9]);
    }

    public function test_the_counter_picks_the_type_of_donation_and_the_record_counts_it(): void
    {
        $receptionist = User::factory()->bloodCenterStaff($this->centre, StaffRole::MedicalReceptionist)->create();
        $donor = User::factory()->donor()->create();

        $id = $this->actingAs($receptionist)
            ->postJson('/api/blood-center/donations', ['donor_uuid' => $donor->uuid, 'donation_type' => 'ts'])
            ->assertCreated()
            ->assertJsonPath('data.donation_type', 'ts')
            ->assertJsonPath('data.donation_type_label', 'TS')
            ->json('data.id');
        Donation::query()->whereKey($id)->update(['status' => DonationStatus::Completed, 'donation_date' => '2026-10-05 02:00']);

        $given = ['facility_id' => $this->centre->id, 'status' => DonationStatus::Completed];
        Donation::factory()->create($given + ['donation_date' => '2026-10-06 02:00', 'donation_type' => DonationType::NonTypeSpecific]);
        Donation::factory()->create($given + ['donation_date' => '2026-10-07 02:00']);

        $kind = $this->report()['donor_profile']['total']['kind'];

        $this->assertSame(['walk_in' => 1, 'ts' => 1, 'nts' => 1, 'drive' => 0], $kind);
    }

    public function test_a_donation_left_without_a_type_is_a_walk_in_and_an_unknown_type_is_refused(): void
    {
        $receptionist = User::factory()->bloodCenterStaff($this->centre, StaffRole::MedicalReceptionist)->create();

        $this->actingAs($receptionist)->postJson('/api/blood-center/donations', ['donor_uuid' => User::factory()->donor()->create()->uuid])
            ->assertCreated()
            ->assertJsonPath('data.donation_type', 'walk_in');

        $this->actingAs($receptionist)->postJson('/api/blood-center/donations', ['donor_uuid' => User::factory()->donor()->create()->uuid, 'donation_type' => 'replacement'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('donation_type');
    }

    public function test_the_snbc_record_downloads_as_the_centres_excel_sheet(): void
    {
        $given = ['facility_id' => $this->centre->id, 'status' => DonationStatus::Completed];
        $donor = DonorProfile::factory()->create(['gender' => 'female', 'birth_date' => '2000-01-15']);
        Donation::factory()->create($given + ['donor_id' => $donor->donor_id, 'donation_date' => '2026-10-05 02:00', 'donation_type' => DonationType::TypeSpecific]);

        $response = $this->actingAs($this->physician)
            ->get('/api/blood-center/reports/collection/snbc-record?from=2026-10-01&to=2026-10-20')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('redagos-snbc-record-2026-10-01-to-2026-10-20.xlsx');

        $sheet = $this->xlsxPart((string) $response->getContent(), 'xl/worksheets/sheet1.xml');

        // Where ext-zip is installed, it must read the same workbook.
        if (class_exists(\ZipArchive::class)) {
            $path = tempnam(sys_get_temp_dir(), 'snbc');
            file_put_contents($path, $response->getContent());
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path, \ZipArchive::CHECKCONS));
            $this->assertSame($sheet, $zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->close();
            @unlink($path);
        }

        foreach (['COLLECTION RECORD (SNBC RECORD)', $this->centre->name, 'TYPE OF DONOR', 'DONOR STATUS', 'October 2026', 'TOTAL'] as $text) {
            $this->assertStringContainsString(htmlspecialchars($text, ENT_XML1 | ENT_QUOTES), $sheet);
        }
        // Row 9, October: one female, 25-44, a walk-in who is TS, total 1.
        $this->assertStringContainsString('<c r="B9" s="6"><v>1</v></c>', $sheet);
        $this->assertStringContainsString('<c r="I9" s="6"><v>1</v></c>', $sheet);
        $this->assertStringContainsString('<c r="J9" s="6"><v>1</v></c>', $sheet);
        $this->assertStringContainsString('<c r="K9" s="6"><v>0</v></c>', $sheet);
        $this->assertStringContainsString('<c r="Q9" s="6"><v>1</v></c>', $sheet);
    }

    public function test_the_busiest_hours_follow_the_centres_opening_hours(): void
    {
        $this->centre->update(['operating_hours' => 'Mon - Fri 8 AM - 3 PM', 'slots_start_at' => '08:00', 'slots_end_at' => '15:00']);

        $this->assertSame(['days' => [0, 1, 2, 3, 4], 'from' => 8, 'to' => 16, 'label' => 'Mon - Fri 8 AM - 3 PM'], $this->report()['opening_hours']);

        $this->centre->update(['operating_hours' => 'Mon, Wed and Sat', 'slots_start_at' => '07:00', 'slots_end_at' => '16:30']);
        $this->physician = $this->physician->fresh();
        $hours = $this->report()['opening_hours'];
        $this->assertSame([0, 2, 5], $hours['days']);
        $this->assertSame([7, 17], [$hours['from'], $hours['to']]);

        $this->centre->update(['operating_hours' => '24/7']);
        $this->physician = $this->physician->fresh();
        $this->assertSame([range(0, 6), 0, 24], array_values(array_slice($this->report()['opening_hours'], 0, 3)));
    }

    public function test_the_report_downloads_as_a_pdf(): void
    {
        Donation::factory()->create(['facility_id' => $this->centre->id, 'donation_date' => '2026-10-05 02:00', 'status' => DonationStatus::Completed]);

        $this->actingAs($this->physician)
            ->get('/api/blood-center/reports/collection/pdf?from=2026-10-01&to=2026-10-20')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('redagos-collection-report-2026-10-01-to-2026-10-20.pdf');
    }

    public function test_someone_outside_collection_may_not_read_it(): void
    {
        $this->actingAs(User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create())
            ->getJson('/api/blood-center/reports/collection?from=2026-10-01&to=2026-10-20')
            ->assertForbidden();
    }
}
