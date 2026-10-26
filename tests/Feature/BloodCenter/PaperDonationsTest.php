<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\BloodCollection;
use App\Models\BloodType;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonationImmunohematology;
use App\Models\DonationScreening;
use App\Models\EligibilityScreening;
use App\Models\Facility;
use App\Models\FacilityDonor;
use App\Models\MobileEvent;
use App\Models\PaperDonationSheet;
use App\Models\User;
use App\Notifications\DonationRecorded;
use App\Notifications\DonorDeferred;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A drive's paper records, typed in after the drive (project owner, 2026-10-17).
 *
 * What is locked in: a paper record becomes an ordinary donation dated on the
 * drive's day, so its bag reaches Testing and the donor's interval runs from
 * the day they gave; the names on the paper are kept beside the account that
 * typed them; and a record typed in error can be taken back only until another
 * department has acted on it.
 */
class PaperDonationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $phlebotomist;

    private User $donor;

    private string $heldOn;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->phlebotomist = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create();
        $this->donor = User::factory()->donor()->create();
        $this->heldOn = OperationalDay::today()->subDays(5)->toDateString();
    }

    /**
     * A clock time on the drive's day, as the client sends it.
     */
    private function at(string $time, ?string $day = null): string
    {
        return OperationalDay::moment($day ?? $this->heldOn, $time)->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    private function openSheet(array $overrides = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->phlebotomist)
            ->postJson('/api/blood-center/paper-sheets', [
                'drive_name' => 'Barangay San Isidro drive',
                'location' => 'San Isidro covered court',
                'held_on' => $this->heldOn,
                'starts_at' => '08:00',
                'screened_by_name' => 'Dr. Reyes',
                'phlebotomist_name' => 'J. Cruz',
                ...$overrides,
            ])
            ->assertCreated()
            ->json('data');
    }

    /**
     * @return array<string, mixed>
     */
    private function drawn(array $overrides = [], array $box = []): array
    {
        return [
            'donor_uuid' => $this->donor->uuid,
            'screened_by_name' => 'Dr. Reyes',
            'outcome' => 'accepted',
            'systolic_bp' => 120,
            'diastolic_bp' => 80,
            'weight_kg' => 62,
            'collection' => [
                'blood_bag_type' => 'single',
                // Typed in lower case with a trailing space, as staff do.
                'donation_barcode' => 'pd-0001 ',
                'started_at' => $this->at('09:30'),
                'ended_at' => $this->at('09:41'),
                'volume_ml' => 450,
                'phlebotomist_name' => 'J. Cruz',
                ...$box,
            ],
            ...$overrides,
        ];
    }

    private function record(int $sheetId, array $payload, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->phlebotomist)
            ->postJson("/api/blood-center/paper-sheets/{$sheetId}/donations", $payload);
    }

    public function test_a_sheet_is_opened_for_a_drive_outside_redagos_and_never_for_a_future_day(): void
    {
        $sheet = $this->openSheet();

        $this->assertSame('PS-'.str_pad((string) $sheet['id'], 6, '0', STR_PAD_LEFT), $sheet['code']);
        $this->assertSame('Barangay San Isidro drive', $sheet['drive']['name']);
        $this->assertFalse($sheet['drive']['in_redagos']);
        $this->assertSame($this->heldOn, $sheet['held_on']);
        $this->assertSame('08:00', $sheet['starts_at']);
        $this->assertTrue($sheet['notify_donors']);

        $this->actingAs($this->phlebotomist)
            ->postJson('/api/blood-center/paper-sheets', [
                'drive_name' => 'Tomorrow',
                'held_on' => OperationalDay::today()->addDay()->toDateString(),
                'starts_at' => '08:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('held_on');

        // Any past day is allowed: the records may be digitised long after.
        $this->openSheet(['held_on' => OperationalDay::today()->subYears(2)->toDateString()]);
    }

    public function test_a_redagos_drive_names_the_sheet_and_fixes_its_day(): void
    {
        $drive = MobileEvent::factory()->past()->create(['facility_id' => $this->facility->id, 'name' => 'Poblacion drive']);
        $driveDay = $drive->event_date->toDateString();

        $options = $this->actingAs($this->phlebotomist)->getJson('/api/blood-center/paper-sheets/options')->assertOk();
        $this->assertContains($drive->id, array_column($options->json('drives'), 'id'));

        $this->actingAs($this->phlebotomist)
            ->postJson('/api/blood-center/paper-sheets', [
                'mobile_event_id' => $drive->id,
                'held_on' => Carbon::parse($driveDay)->subDay()->toDateString(),
                'starts_at' => '08:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('held_on');

        $sheet = $this->openSheet(['mobile_event_id' => $drive->id, 'drive_name' => null, 'held_on' => $driveDay]);

        $this->assertSame('Poblacion drive', $sheet['drive']['name']);
        $this->assertTrue($sheet['drive']['in_redagos']);

        // Another centre's drive is not offered and not accepted.
        $other = MobileEvent::factory()->past()->create();
        $this->actingAs($this->phlebotomist)
            ->postJson('/api/blood-center/paper-sheets', ['mobile_event_id' => $other->id, 'held_on' => $other->event_date->toDateString(), 'starts_at' => '08:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mobile_event_id');
    }

    public function test_a_drawn_record_is_a_donation_on_the_drive_day_that_goes_to_testing(): void
    {
        $sheet = $this->openSheet();

        $row = $this->record($sheet['id'], $this->drawn())->assertCreated()->json('data');

        $donation = Donation::with(['screening', 'collection'])->findOrFail($row['id']);

        $this->assertSame(DonationStatus::Collected, $donation->status);
        $this->assertSame($sheet['id'], $donation->paper_sheet_id);
        $this->assertSame($this->heldOn, OperationalDay::dateOf($donation->donation_date));
        $this->assertSame(450, $donation->volume_ml);

        // The paper's names, beside the account that typed them.
        $this->assertSame($this->phlebotomist->id, $donation->screening->recorded_by);
        $this->assertSame('Dr. Reyes', $donation->screening->screened_by_name);
        $this->assertTrue($donation->screening->dhq_on_paper);
        $this->assertSame($this->phlebotomist->id, $donation->collection->collected_by);
        $this->assertSame('J. Cruz', $donation->collection->phlebotomist_name);
        $this->assertSame('PD-0001', $donation->collection->donation_barcode);
        $this->assertSame($this->heldOn, OperationalDay::dateOf($donation->collection->ended_at));

        // The row reads like any other on the Donations list, marked as paper.
        $this->assertSame('paper', $row['source']);
        $this->assertSame('Barangay San Isidro drive', $row['drive']);
        $this->assertSame('Dr. Reyes', $row['screening']['recorded_by']);
        $this->assertSame('J. Cruz', $row['collection']['collected_by']);
        $this->assertSame(trim($this->phlebotomist->first_name.' '.$this->phlebotomist->last_name), $row['paper_sheet']['entered_by']);
        $this->assertTrue($row['can']['remove']);

        $testing = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $queue = $this->actingAs($testing)->getJson('/api/blood-center/laboratory/queue')->assertOk()->json('data');
        $this->assertContains($donation->id, array_column($queue, 'id'));

        $listed = collect($this->actingAs($this->phlebotomist)->getJson('/api/blood-center/donation-records')->assertOk()->json('data'))
            ->firstWhere('id', $donation->id);
        $this->assertSame('paper', $listed['source']);
        $this->assertSame($sheet['code'], $listed['paper_sheet']['code']);
    }

    public function test_the_donor_waits_from_the_drive_day_not_from_the_day_it_was_typed(): void
    {
        $receptionist = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
        FacilityDonor::create(['facility_id' => $this->facility->id, 'donor_id' => $this->donor->id, 'registered_here' => true]);

        $this->record($this->openSheet()['id'], $this->drawn())->assertCreated();

        $row = collect($this->actingAs($receptionist)->getJson('/api/blood-center/donors')->assertOk()->json('data'))
            ->firstWhere('uuid', $this->donor->uuid);

        $this->assertSame('waiting', $row['eligibility']);
        $this->assertSame(
            Carbon::parse($this->heldOn)->addDays((int) config('donation.interval_days'))->toDateString(),
            $row['next_eligible_date']
        );

        // A drive long enough ago leaves the donor free to give again.
        $old = User::factory()->donor()->create();
        FacilityDonor::create(['facility_id' => $this->facility->id, 'donor_id' => $old->id, 'registered_here' => true]);
        $longAgo = OperationalDay::today()->subDays(90)->toDateString();
        $oldSheet = $this->openSheet(['held_on' => $longAgo]);

        $this->record($oldSheet['id'], $this->drawn(['donor_uuid' => $old->uuid], [
            'donation_barcode' => 'PD-0090',
            'started_at' => $this->at('09:30', $longAgo),
            'ended_at' => $this->at('09:40', $longAgo),
        ]))->assertCreated();

        $oldRow = collect($this->actingAs($receptionist)->getJson('/api/blood-center/donors')->json('data'))->firstWhere('uuid', $old->uuid);
        $this->assertSame('eligible', $oldRow['eligibility']);
    }

    public function test_a_deferral_defers_the_donor_and_a_failed_draw_closes_without_a_bag(): void
    {
        $sheet = $this->openSheet();

        $deferred = $this->record($sheet['id'], [
            'donor_uuid' => $this->donor->uuid,
            'screened_by_name' => 'Dr. Reyes',
            'outcome' => 'temporarily_deferred',
            'deferral_code' => 'R2-Low',
            'haemoglobin_g_dl' => 11.2,
        ])->assertCreated()->json('data');

        $this->assertSame('rejected', $deferred['status']);
        $this->assertSame('Deferred', $deferred['status_label']);
        $this->assertNull($deferred['bag']);
        // The code the paper gives, as the counter's would carry it.
        $this->assertSame('R2-Low', Donation::find($deferred['id'])->rejection_reason);
        $this->assertSame('Low haemoglobin', DonationScreening::where('donation_id', $deferred['id'])->value('deferral_reason'));

        // A deferred donor carries no phlebotomist box.
        $other = User::factory()->donor()->create();
        $this->record($sheet['id'], [
            'donor_uuid' => $other->uuid,
            'screened_by_name' => 'Dr. Reyes',
            'outcome' => 'permanently_deferred',
            'deferral_code' => 'R1',
            'collection' => $this->drawn()['collection'],
        ])->assertUnprocessable()->assertJsonValidationErrors('collection');

        $failed = $this->record($sheet['id'], [
            'donor_uuid' => $other->uuid,
            'screened_by_name' => 'Dr. Reyes',
            'outcome' => 'accepted',
            'draw_failed' => true,
            'draw_failed_reason' => 'Vein collapsed',
        ])->assertCreated()->json('data');

        $this->assertSame('rejected', $failed['status']);
        $this->assertFalse(BloodCollection::where('donation_id', $failed['id'])->exists());
    }

    public function test_a_bag_that_will_not_reach_the_laboratory_still_counts_for_the_donor(): void
    {
        $row = $this->record($this->openSheet()['id'], $this->drawn([
            'not_to_lab' => true,
            'not_to_lab_reason' => 'Bag broken in transport',
        ]))->assertCreated()->json('data');

        $donation = Donation::findOrFail($row['id']);
        $this->assertSame(DonationStatus::Rejected, $donation->status);
        $this->assertTrue($donation->collection()->exists());

        $testing = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $queue = $this->actingAs($testing)->getJson('/api/blood-center/laboratory/queue')->json('data');
        $this->assertNotContains($donation->id, array_column($queue, 'id'));

        // The reason is needed.
        $this->record($this->openSheet()['id'], $this->drawn([
            'donor_uuid' => User::factory()->donor()->create()->uuid,
            'not_to_lab' => true,
        ], ['donation_barcode' => 'PD-0002']))->assertUnprocessable()->assertJsonValidationErrors('not_to_lab_reason');
    }

    public function test_every_time_must_fall_on_the_drive_day(): void
    {
        $sheet = $this->openSheet();
        $otherDay = OperationalDay::today()->subDays(4)->toDateString();

        $this->record($sheet['id'], $this->drawn([], ['ended_at' => $this->at('09:41', $otherDay)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('collection.ended_at');

        $this->record($sheet['id'], $this->drawn(['screened_at' => $this->at('09:00', $otherDay)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('screened_at');

        // A screening time, when given, dates the visit.
        $row = $this->record($sheet['id'], $this->drawn(['screened_at' => $this->at('09:05')]))->assertCreated()->json('data');
        $this->assertSame('09:05', Carbon::parse(Donation::find($row['id'])->donation_date)->setTimezone(config('blood_center.timezone'))->format('H:i'));
    }

    public function test_a_barcode_already_used_and_a_donor_already_on_the_sheet_are_refused(): void
    {
        $sheet = $this->openSheet();
        $this->record($sheet['id'], $this->drawn())->assertCreated();

        $this->record($sheet['id'], $this->drawn([], ['donation_barcode' => 'PD-0003']))
            ->assertConflict()
            ->assertJsonPath('code', 'already_on_sheet');

        $this->record($sheet['id'], $this->drawn(['donor_uuid' => User::factory()->donor()->create()->uuid]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('collection.donation_barcode');
    }

    public function test_a_draw_too_close_to_another_must_be_confirmed(): void
    {
        Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'status' => DonationStatus::Completed,
            'donation_date' => Carbon::parse($this->heldOn.' 10:00')->subDays(30),
        ]);

        $sheet = $this->openSheet();

        $this->record($sheet['id'], $this->drawn())
            ->assertConflict()
            ->assertJsonPath('code', 'interval_conflict')
            ->assertJsonPath('data.date', Carbon::parse($this->heldOn)->subDays(30)->toDateString());

        $this->assertSame(0, Donation::where('paper_sheet_id', $sheet['id'])->count());

        $this->record($sheet['id'], $this->drawn(['confirm_interval' => true]))->assertCreated();
    }

    public function test_the_donors_drive_booking_is_closed_by_the_record_and_reopened_if_it_is_removed(): void
    {
        $drive = MobileEvent::factory()->past()->create(['facility_id' => $this->facility->id]);
        $booking = DonationAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'event_id' => $drive->id,
            'appointment_datetime' => $drive->event_date->copy()->setTime(9, 0),
        ]);

        $sheet = $this->openSheet(['mobile_event_id' => $drive->id, 'drive_name' => null, 'held_on' => $drive->event_date->toDateString()]);
        $day = $drive->event_date->toDateString();

        $row = $this->record($sheet['id'], $this->drawn([], [
            'started_at' => $this->at('09:30', $day),
            'ended_at' => $this->at('09:41', $day),
        ]))->assertCreated()->json('data');

        $this->assertSame($booking->id, $row['appointment']['id']);
        $this->assertSame(AppointmentStatus::Completed, $booking->fresh()->status);

        $this->actingAs($this->phlebotomist)
            ->deleteJson("/api/blood-center/paper-sheets/{$sheet['id']}/donations/{$row['id']}", ['reason' => 'Wrong donor'])
            ->assertOk();

        $this->assertSame(AppointmentStatus::Scheduled, $booking->fresh()->status);
    }

    public function test_the_donor_is_told_only_when_the_sheet_says_so(): void
    {
        $this->record($this->openSheet()['id'], $this->drawn())->assertCreated();
        Notification::assertSentTo($this->donor, DonationRecorded::class);

        $quiet = $this->openSheet(['notify_donors' => false]);
        $other = User::factory()->donor()->create();
        $this->record($quiet['id'], [
            'donor_uuid' => $other->uuid,
            'screened_by_name' => 'Dr. Reyes',
            'outcome' => 'temporarily_deferred',
            'deferral_code' => 'R1',
        ])->assertCreated();

        Notification::assertNotSentTo($other, DonorDeferred::class);
    }

    public function test_only_the_questionnaire_the_drive_used_is_retired(): void
    {
        $before = EligibilityScreening::factory()->create([
            'donor_id' => $this->donor->id,
            'screened_at' => Carbon::parse($this->heldOn)->subDays(2),
            'valid_until' => now()->addDays(20),
        ]);
        $after = EligibilityScreening::factory()->create([
            'donor_id' => $this->donor->id,
            'screened_at' => now()->subDay(),
            'valid_until' => now()->addDays(20),
        ]);

        $this->record($this->openSheet()['id'], $this->drawn())->assertCreated();

        $this->assertNotNull($before->fresh()->invalidated_at);
        $this->assertNull($after->fresh()->invalidated_at);
    }

    public function test_a_record_is_taken_back_only_before_the_laboratory_and_by_whoever_typed_it(): void
    {
        $sheet = $this->openSheet();
        $row = $this->record($sheet['id'], $this->drawn())->assertCreated()->json('data');
        $url = "/api/blood-center/paper-sheets/{$sheet['id']}/donations/{$row['id']}";

        $colleague = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create();
        $this->actingAs($colleague)->deleteJson($url, ['reason' => 'Typo'])->assertForbidden();

        $this->actingAs($this->phlebotomist)->deleteJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('reason');

        DonationImmunohematology::create([
            'donation_id' => $row['id'],
            'blood_type_id' => BloodType::factory()->create()->id,
            'recorded_by' => $colleague->id,
            'recorded_at' => now(),
        ]);

        $this->actingAs($this->phlebotomist)->deleteJson($url, ['reason' => 'Typo'])
            ->assertConflict()
            ->assertJsonPath('code', 'entry_in_use');

        DonationImmunohematology::where('donation_id', $row['id'])->delete();

        // The Collection head may take back anyone's.
        $head = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $this->actingAs($head)->deleteJson($url, ['reason' => 'Typed under the wrong donor'])->assertOk();

        $this->assertNull(Donation::find($row['id']));
        $this->assertFalse(DonationScreening::where('donation_id', $row['id'])->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'paper.donation_removed']);
    }

    public function test_a_new_donor_on_the_paper_is_registered_by_whoever_types_it(): void
    {
        $donor = $this->actingAs($this->phlebotomist)
            ->postJson('/api/blood-center/paper-sheets/donors', [
                'first_name' => 'Ana',
                'last_name' => 'Santos',
                'valid_id_number' => 'P-1234567',
                'birth_date' => now()->subYears(30)->toDateString(),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotEmpty($donor['uuid']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'donor.registered_from_paper']);

        // The desk's own route stays the desk's.
        $this->actingAs($this->phlebotomist)
            ->postJson('/api/blood-center/donors', ['first_name' => 'X'])
            ->assertForbidden();
    }

    public function test_only_an_empty_sheet_is_removed(): void
    {
        $sheet = $this->openSheet();
        $this->record($sheet['id'], $this->drawn())->assertCreated();

        $this->actingAs($this->phlebotomist)->deleteJson("/api/blood-center/paper-sheets/{$sheet['id']}")
            ->assertConflict()
            ->assertJsonPath('code', 'sheet_not_empty');

        $empty = $this->openSheet();
        $this->actingAs($this->phlebotomist)->deleteJson("/api/blood-center/paper-sheets/{$empty['id']}")->assertOk();
        $this->assertNull(PaperDonationSheet::find($empty['id']));
    }

    public function test_the_whole_collection_department_may_use_it_and_no_one_else(): void
    {
        foreach ([StaffRole::MedicalReceptionist, StaffRole::ScreeningPhysician, StaffRole::ApheresisSpecialist] as $role) {
            $this->actingAs(User::factory()->bloodCenterStaff($this->facility, $role)->create())
                ->getJson('/api/blood-center/paper-sheets')
                ->assertOk();
        }

        foreach ([StaffRole::SerologyTechnologist, StaffRole::InventoryControlOfficer, StaffRole::BillingClerk] as $role) {
            $this->actingAs(User::factory()->bloodCenterStaff($this->facility, $role)->create())
                ->getJson('/api/blood-center/paper-sheets')
                ->assertForbidden();
        }

        // Another centre's sheet is not found.
        $sheet = $this->openSheet();
        $elsewhere = User::factory()->bloodCenterStaff(Facility::factory()->approved()->create(), StaffRole::Phlebotomist)->create();
        $this->actingAs($elsewhere)->getJson("/api/blood-center/paper-sheets/{$sheet['id']}")->assertNotFound();
        $this->record($sheet['id'], $this->drawn(), $elsewhere)->assertNotFound();
    }
}
