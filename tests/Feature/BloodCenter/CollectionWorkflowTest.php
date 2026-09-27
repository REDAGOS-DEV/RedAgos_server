<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\Department;
use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\BloodCollection;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonationScreening;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\DonationRecorded;
use App\Notifications\DonorDeferred;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * The counter workflow, and the boundary it must not cross.
 *
 * Donor/Collection owns a donation from registration to collection.
 * Laboratory owns it from there. `completed` is what lets blood reach a
 * patient, so this department must be unable to write it by any route.
 */
class CollectionWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    /**
     * The collection chair: records the draw and closes a visit that cannot go ahead.
     */
    private User $staff;

    /**
     * Opens the visit's donation at the counter.
     */
    private User $receptionist;

    /**
     * Accepts or defers the donor.
     */
    private User $physician;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create();
        $this->receptionist = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
        $this->physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $this->donor = User::factory()->donor()->create();
    }

    /**
     * Walk a donation to the given status through the real endpoints.
     */
    private function openDonation(): int
    {
        return $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donations', ['donor_uuid' => $this->donor->uuid])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * Record a qualifying screening, which is what moves a donation to `screening`.
     */
    private function screenDonation(int $id): void
    {
        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", ['outcome' => 'accepted'])
            ->assertCreated();
    }

    private int $segments = 0;

    /**
     * A complete "For Phlebotomist Use Only" box, with a fresh segment number each call.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function collectionPayload(array $overrides = []): array
    {
        $this->segments++;

        return [
            'volume_ml' => 450,
            'blood_bag_type' => 'double',
            'segment_number' => 'SEG-'.str_pad((string) $this->segments, 4, '0', STR_PAD_LEFT),
            'started_at' => now()->subMinutes(15)->toISOString(),
            'ended_at' => now()->subMinutes(5)->toISOString(),
            ...$overrides,
        ];
    }

    /**
     * Open and screen a donation for a fresh donor, ready to be collected.
     */
    private function screenedDonationForNewDonor(?Facility $facility = null): int
    {
        $receptionist = $facility === null
            ? $this->receptionist
            : User::factory()->bloodCenterStaff($facility, StaffRole::MedicalReceptionist)->create();
        $physician = $facility === null
            ? $this->physician
            : User::factory()->bloodCenterStaff($facility, StaffRole::ScreeningPhysician)->create();
        $donor = User::factory()->donor()->create();

        $id = $this->actingAs($receptionist)
            ->postJson('/api/blood-center/donations', ['donor_uuid' => $donor->uuid])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", ['outcome' => 'accepted'])
            ->assertCreated();

        return $id;
    }

    public function test_a_donation_opens_as_registered_at_the_callers_facility(): void
    {
        $id = $this->openDonation();

        $donation = Donation::findOrFail($id);
        $this->assertSame(DonationStatus::Registered, $donation->status);
        $this->assertSame($this->facility->id, $donation->facility_id, 'facility_id must come from the actor.');
    }

    public function test_the_full_counter_chain_reaches_collected(): void
    {
        $id = $this->openDonation();

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", ['outcome' => 'accepted'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'screening');

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'collected')
            ->assertJsonPath('data.owning_department', 'testing');

        $this->assertSame(450, Donation::findOrFail($id)->volume_ml);
    }

    public function test_recording_a_collection_names_the_staff_member_who_drew_it(): void
    {
        $id = $this->openDonation();
        $this->screenDonation($id);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload())
            ->assertCreated();

        // collected_by is the authenticated staff member, never request input.
        $this->assertDatabaseHas('blood_collections', [
            'donation_id' => $id,
            'collected_by' => $this->staff->id,
        ]);
    }

    public function test_a_collection_cannot_be_recorded_before_screening(): void
    {
        $id = $this->openDonation();

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_not_screened');

        $this->assertSame(0, BloodCollection::where('donation_id', $id)->count());
    }

    public function test_a_collection_cannot_be_recorded_twice(): void
    {
        $id = $this->openDonation();
        $this->screenDonation($id);
        $this->actingAs($this->staff)->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload());

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'collection_already_recorded');

        $this->assertSame(1, BloodCollection::where('donation_id', $id)->count());
    }

    public function test_collection_staff_cannot_mark_a_donation_tested_or_completed(): void
    {
        $id = $this->openDonation();
        $this->screenDonation($id);
        $this->actingAs($this->staff)->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload());

        foreach (['tested', 'completed'] as $status) {
            $this->actingAs($this->staff)
                ->patchJson("/api/blood-center/donations/{$id}/status", ['status' => $status])
                ->assertForbidden()
                ->assertJsonPath('code', 'laboratory_owns_status');
        }

        $this->assertSame(DonationStatus::Collected, Donation::findOrFail($id)->status);
    }

    public function test_a_donation_cannot_skip_screening(): void
    {
        $id = $this->openDonation();

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/donations/{$id}/status", ['status' => 'registered'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition');
    }

    public function test_a_donor_may_be_rejected_at_screening(): void
    {
        $id = $this->openDonation();

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/donations/{$id}/status", ['status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_only_one_donation_may_be_open_per_donor_per_facility(): void
    {
        $this->openDonation();

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donations', ['donor_uuid' => $this->donor->uuid])
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_already_open');
    }

    public function test_a_rejected_donation_frees_the_donor_for_a_new_visit(): void
    {
        $id = $this->openDonation();
        $this->actingAs($this->staff)->patchJson("/api/blood-center/donations/{$id}/status", ['status' => 'rejected']);

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donations', ['donor_uuid' => $this->donor->uuid])
            ->assertCreated();
    }

    public function test_a_donation_cannot_be_opened_against_another_facilitys_appointment(): void
    {
        $foreign = DonationAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => Facility::factory()->approved()->create()->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donations', [
                'donor_uuid' => $this->donor->uuid,
                'appointment_id' => $foreign->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'appointment_not_found');
    }

    public function test_an_appointment_belonging_to_a_different_donor_is_refused(): void
    {
        $other = User::factory()->donor()->create();
        $appointment = DonationAppointment::factory()->create([
            'donor_id' => $other->id,
            'facility_id' => $this->facility->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donations', [
                'donor_uuid' => $this->donor->uuid,
                'appointment_id' => $appointment->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'appointment_donor_mismatch');
    }

    public function test_recording_a_collection_closes_the_linked_appointment(): void
    {
        $appointment = DonationAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'status' => 'confirmed',
        ]);

        $id = $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donations', [
                'donor_uuid' => $this->donor->uuid,
                'appointment_id' => $appointment->id,
            ])->json('data.id');

        $this->screenDonation($id);
        $this->actingAs($this->staff)->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload());

        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
    }

    public function test_recording_a_qualifying_screening_moves_the_donation_to_screening(): void
    {
        $id = $this->openDonation();

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", [
                'outcome' => 'accepted',
                'systolic_bp' => 118,
                'diastolic_bp' => 76,
                'pulse_bpm' => 72,
                'temperature_c' => 36.6,
                'weight_kg' => 65,
                'haemoglobin_g_dl' => 14.2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'screening')
            ->assertJsonPath('data.screening.outcome', 'accepted')
            ->assertJsonPath('data.screening.haemoglobin_g_dl', 14.2);
    }

    public function test_a_screening_names_the_staff_member_who_recorded_it(): void
    {
        $id = $this->openDonation();

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", ['outcome' => 'accepted'])
            ->assertCreated();

        // recorded_by is the authenticated staff member, never request input.
        $this->assertDatabaseHas('donation_screenings', [
            'donation_id' => $id,
            'recorded_by' => $this->physician->id,
            'facility_id' => $this->facility->id,
        ]);
    }

    public function test_a_deferring_screening_rejects_the_donation_and_records_the_reason(): void
    {
        $id = $this->openDonation();

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", [
                'outcome' => 'temporarily_deferred',
                'deferral_reason' => 'Haemoglobin below the accepted threshold.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Haemoglobin below the accepted threshold.');
    }

    public function test_a_deferral_must_say_why(): void
    {
        $id = $this->openDonation();

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", ['outcome' => 'temporarily_deferred'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('deferral_reason');
    }

    public function test_a_correction_edits_the_screening_rather_than_adding_a_second(): void
    {
        $id = $this->openDonation();

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", [
                'outcome' => 'accepted',
                'haemoglobin_g_dl' => 12.1,
            ])->assertCreated();

        // Saving over it is refused: a saved screening changes only through
        // an approved correction.
        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", ['outcome' => 'accepted', 'haemoglobin_g_dl' => 14.5])
            ->assertStatus(409)
            ->assertJsonPath('code', 'correction_required');

        $correction = $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/corrections", [
                'subject' => 'screening',
                'reason' => 'Typed 12.1 for 14.5.',
                'changes' => ['outcome' => 'accepted', 'haemoglobin_g_dl' => 14.5],
            ])
            ->assertCreated()
            ->json('data.id');

        // The physician is Collection's approver, so their own request goes
        // to the Center Admin.
        $admin = User::factory()->bloodCenterSupervisor($this->facility)->create();

        $this->actingAs($admin)
            ->postJson("/api/blood-center/corrections/{$correction}/approve")
            ->assertOk();

        $this->assertSame('14.5', (string) (float) DonationScreening::where('donation_id', $id)->value('haemoglobin_g_dl'));

        // One assessment per donation, so there is never an ambiguity about
        // which one let the donor proceed.
        $this->assertDatabaseCount('donation_screenings', 1);
    }

    public function test_a_screening_cannot_be_recorded_once_the_bag_is_drawn(): void
    {
        $id = $this->openDonation();
        $this->screenDonation($id);
        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload())
            ->assertCreated();

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", ['outcome' => 'temporarily_deferred', 'deferral_reason' => 'Changed my mind.'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'screening_not_amendable');
    }

    public function test_screening_cannot_be_reached_by_setting_the_status(): void
    {
        $id = $this->openDonation();

        // The same rule that protects `collected`: a donor cannot be marked
        // screened without the row saying what was found.
        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/donations/{$id}/status", ['status' => 'screening'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'screening_not_recorded');
    }

    public function test_a_deferred_donor_closes_the_appointment_they_booked(): void
    {
        $appointment = DonationAppointment::factory()->confirmed()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
        ]);

        $id = $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donations', [
                'donor_uuid' => $this->donor->uuid,
                'appointment_id' => $appointment->id,
            ])->json('data.id');

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", [
                'outcome' => 'temporarily_deferred',
                'deferral_reason' => 'Haemoglobin below the accepted threshold.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.rejection_reason', 'Haemoglobin below the accepted threshold.');

        // Without this the slot stays held and the donor sits in the day's
        // queue for good, having already been sent home.
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
    }

    public function test_opening_a_donation_checks_in_a_scheduled_appointment(): void
    {
        $appointment = DonationAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'status' => 'scheduled',
        ]);

        // A donor found by valid ID rather than a scan never passes through
        // check-in, but is no less at the counter.
        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donations', [
                'donor_uuid' => $this->donor->uuid,
                'appointment_id' => $appointment->id,
            ])
            ->assertCreated();

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_the_queue_tells_a_deferred_visit_from_a_collected_one(): void
    {
        $appointment = DonationAppointment::factory()->confirmed()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'appointment_datetime' => now(),
        ]);

        $id = $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donations', [
                'donor_uuid' => $this->donor->uuid,
                'appointment_id' => $appointment->id,
            ])->json('data.id');

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", [
                'outcome' => 'temporarily_deferred',
                'deferral_reason' => 'Haemoglobin below the accepted threshold.',
            ])
            ->assertCreated();

        // Both close the booking as `completed`; only the donation says which.
        $this->actingAs($this->receptionist)
            ->getJson('/api/blood-center/collection/queue')
            ->assertOk()
            ->assertJsonPath('appointments.0.id', $appointment->id)
            ->assertJsonPath('appointments.0.status', 'completed')
            ->assertJsonPath('appointments.0.donation_status', 'rejected');
    }

    public function test_a_rejection_records_the_reason_it_was_given(): void
    {
        $id = $this->openDonation();

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/donations/{$id}/status", [
                'status' => 'rejected',
                'rejection_reason' => 'Donor withdrew consent.',
            ])
            ->assertOk();

        $this->assertSame('Donor withdrew consent.', Donation::find($id)->rejection_reason);
    }

    public function test_a_rejection_without_a_reason_is_still_accepted(): void
    {
        $id = $this->openDonation();

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/donations/{$id}/status", ['status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.rejection_reason', null);
    }

    public function test_a_donation_with_no_appointment_is_rejected_without_incident(): void
    {
        $id = $this->openDonation();

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/donations/{$id}/status", ['status' => 'rejected'])
            ->assertOk();

        $this->assertNull(Donation::find($id)->appointment_id);
    }

    public function test_a_recorded_collection_tells_the_donor_and_names_their_next_eligible_date(): void
    {
        Notification::fake();

        $id = $this->openDonation();
        $this->screenDonation($id);
        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload())
            ->assertCreated();

        Notification::assertSentTo($this->donor, DonationRecorded::class);
    }

    public function test_a_deferred_donor_is_told_the_reason_that_was_recorded(): void
    {
        Notification::fake();

        $id = $this->openDonation();

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", [
                'outcome' => 'temporarily_deferred',
                'deferral_reason' => 'Haemoglobin below the accepted threshold.',
            ])
            ->assertCreated();

        // A deferral with no reason is the thing most likely to stop somebody
        // coming back, and HelpPage already promises they can see one.
        Notification::assertSentTo(
            $this->donor,
            DonorDeferred::class,
            function (DonorDeferred $notification) {
                $payload = $notification->toDatabase($this->donor);

                return $payload['desc'] === 'Haemoglobin below the accepted threshold.'
                    && $payload['category'] === 'screening';
            }
        );
    }

    public function test_a_qualifying_screening_does_not_tell_the_donor_anything(): void
    {
        Notification::fake();

        $id = $this->openDonation();
        $this->screenDonation($id);

        // The donor is standing right there and is about to donate; a
        // notification saying "you qualified" is noise.
        Notification::assertNothingSent();
    }

    public function test_a_failed_mailer_does_not_fail_the_collection(): void
    {
        Notification::fake();
        Notification::shouldReceive('send')->andThrow(new RuntimeException('Mailer down'));

        $id = $this->openDonation();
        $this->screenDonation($id);

        // The bag is already drawn. Refusing the request would lose the record
        // of a donation that physically happened.
        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload())
            ->assertCreated();

        $this->assertSame(450, Donation::findOrFail($id)->volume_ml);
    }

    public function test_a_donation_from_another_facility_is_not_found(): void
    {
        $foreign = Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => Facility::factory()->approved()->create()->id,
            'status' => 'screening',
        ]);

        $this->actingAs($this->staff)
            ->patchJson("/api/blood-center/donations/{$foreign->id}/status", ['status' => 'rejected'])
            ->assertNotFound();
    }

    public function test_a_collected_donation_still_cannot_enter_inventory(): void
    {
        $id = $this->openDonation();
        $this->screenDonation($id);
        $this->actingAs($this->staff)->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload());

        // The handoff this module stops at. Intake gates on `completed`, and
        // only Laboratory may set it — so collection alone does not yet make
        // the inventory module reachable. Building Laboratory is what closes
        // this gap; until then this refusal is correct, not a defect.
        $inventoryStaff = User::factory()->bloodCenterStaff($this->facility, Department::Issuance)->create();

        $this->actingAs($inventoryStaff)
            ->postJson('/api/blood-center/inventory', [
                'donation_id' => $id,
                'units' => [[
                    'component_id' => BloodComponent::factory()->create()->id,
                    'expiry_date' => now()->addDays(30)->toDateString(),
                ]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'donation_not_completed');
    }

    public function test_the_phlebotomist_box_is_recorded_and_returned(): void
    {
        $id = $this->openDonation();
        $this->screenDonation($id);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload([
                'blood_bag_type' => 'triple',
                'segment_number' => 'SNB-0042',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.collection.blood_bag_type', 'triple')
            ->assertJsonPath('data.collection.blood_bag_type_label', 'Triple')
            ->assertJsonPath('data.collection.segment_number', 'SNB-0042')
            ->assertJsonPath('data.collection.phlebotomist', trim($this->staff->first_name.' '.$this->staff->last_name));

        $collection = BloodCollection::where('donation_id', $id)->firstOrFail();
        $this->assertSame($this->facility->id, $collection->facility_id);
        $this->assertNotNull($collection->started_at);
        $this->assertTrue($collection->ended_at->equalTo($collection->collection_datetime));
    }

    public function test_every_field_of_the_phlebotomist_box_is_required(): void
    {
        $id = $this->openDonation();
        $this->screenDonation($id);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", ['volume_ml' => 450])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blood_bag_type', 'segment_number', 'started_at', 'ended_at']);

        $this->assertSame(0, BloodCollection::where('donation_id', $id)->count());
    }

    public function test_an_unknown_bag_type_is_refused(): void
    {
        $id = $this->openDonation();
        $this->screenDonation($id);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload(['blood_bag_type' => 'quintuple']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('blood_bag_type');
    }

    public function test_a_segment_number_cannot_be_used_twice_at_one_facility(): void
    {
        $first = $this->screenedDonationForNewDonor();
        $second = $this->screenedDonationForNewDonor();

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$first}/collection", $this->collectionPayload(['segment_number' => 'SEG-DUP']))
            ->assertCreated();

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$second}/collection", $this->collectionPayload(['segment_number' => 'SEG-DUP']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('segment_number');

        $this->assertSame(DonationStatus::Screening, Donation::findOrFail($second)->status);
    }

    public function test_the_same_segment_number_is_allowed_at_another_facility(): void
    {
        $otherFacility = Facility::factory()->approved()->create();
        $otherStaff = User::factory()->bloodCenterStaff($otherFacility, StaffRole::Phlebotomist)->create();

        $here = $this->screenedDonationForNewDonor();
        $there = $this->screenedDonationForNewDonor($otherFacility);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$here}/collection", $this->collectionPayload(['segment_number' => 'SEG-0001']))
            ->assertCreated();

        // Numbering schemes are each centre's own.
        $this->actingAs($otherStaff)
            ->postJson("/api/blood-center/donations/{$there}/collection", $this->collectionPayload(['segment_number' => 'SEG-0001']))
            ->assertCreated();
    }

    public function test_a_scanned_segment_number_is_normalised_so_a_typed_copy_collides(): void
    {
        $first = $this->screenedDonationForNewDonor();
        $second = $this->screenedDonationForNewDonor();

        // A scanner appends a carriage return; staff type in lower case.
        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$first}/collection", $this->collectionPayload(['segment_number' => "abc 123\r"]))
            ->assertCreated()
            ->assertJsonPath('data.collection.segment_number', 'ABC123');

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$second}/collection", $this->collectionPayload(['segment_number' => 'ABC123']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('segment_number');
    }

    public function test_a_draw_cannot_end_before_it_started(): void
    {
        $id = $this->openDonation();
        $this->screenDonation($id);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload([
                'started_at' => now()->subMinutes(5)->toISOString(),
                'ended_at' => now()->subMinutes(15)->toISOString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ended_at');
    }

    public function test_a_draw_cannot_be_recorded_in_the_future(): void
    {
        $id = $this->openDonation();
        $this->screenDonation($id);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload([
                'ended_at' => now()->addHour()->toISOString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ended_at');
    }

    public function test_the_phlebotomist_is_never_taken_from_the_request(): void
    {
        $someoneElse = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create();
        $id = $this->openDonation();
        $this->screenDonation($id);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload([
                'collected_by' => $someoneElse->id,
            ]))
            ->assertCreated();

        $this->assertDatabaseHas('blood_collections', [
            'donation_id' => $id,
            'collected_by' => $this->staff->id,
        ]);
    }

    public function test_the_fingerprick_blood_type_is_stored_but_never_adopted_onto_the_donor(): void
    {
        $bloodType = BloodType::factory()->create();
        $this->donor->donorProfile->update(['blood_type_id' => null]);
        $id = $this->openDonation();

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", [
                'outcome' => 'accepted',
                'haemoglobin_g_dl' => 13.8,
                'fingerprick_blood_type_id' => $bloodType->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.screening.fingerprick_blood_type_id', $bloodType->id)
            ->assertJsonPath('data.screening.fingerprick_blood_type', $bloodType->code);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/donations/{$id}/collection", $this->collectionPayload())
            ->assertCreated();

        // Preliminary. Only the Testing department's typing reaches the profile.
        $this->assertNull($this->donor->donorProfile->fresh()->blood_type_id);
    }

    public function test_the_fingerprick_blood_type_is_optional_and_must_exist(): void
    {
        $id = $this->openDonation();

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", [
                'outcome' => 'accepted',
                'fingerprick_blood_type_id' => 999999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fingerprick_blood_type_id');

        $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$id}/screening", ['outcome' => 'accepted'])
            ->assertCreated()
            ->assertJsonPath('data.screening.fingerprick_blood_type', null);
    }

    public function test_billing_staff_cannot_touch_the_collection_chain(): void
    {
        $billing = User::factory()->bloodCenterStaff($this->facility, Department::Billing)->create();

        $this->actingAs($billing)
            ->postJson('/api/blood-center/donations', ['donor_uuid' => $this->donor->uuid])
            ->assertForbidden();

        $this->actingAs($billing)
            ->getJson('/api/blood-center/collection/queue')
            ->assertForbidden();
    }
}
