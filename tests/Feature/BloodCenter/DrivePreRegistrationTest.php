<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\PreRegistrationSource;
use App\Enums\PreRegistrationStatus;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DrivePartnerSubmission;
use App\Models\DrivePreRegistration;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\DriveCancelledForRegistrant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A drive's registration link (project owner, 2026-11-11 and 2026-11-12):
 * people without a portal account and partner organisations put people on
 * the drive's list, with no limit and no health questions, and staff tidy the
 * list without ever typing anyone in.
 */
class DrivePreRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $staff;

    private MobileEvent $drive;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create(['is_accepting_donations' => true]);
        $this->staff = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
        $this->drive = MobileEvent::factory()->create([
            'facility_id' => $this->facility->id,
            'event_date' => now()->addWeeks(2)->toDateString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function person(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'phone' => '09171234567',
            'email' => 'Ana.Reyes@Example.com',
            'birth_date' => '1998-05-14',
            'affiliation' => 'UM College of Nursing',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function registerSelf(array $overrides = [], ?MobileEvent $drive = null): TestResponse
    {
        return $this->postJson('/api/drive-links/'.($drive ?? $this->drive)->registration_token.'/registrations', [
            ...$this->person(),
            'consent' => true,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $people
     * @param  array<string, mixed>  $overrides
     */
    private function registerGroup(array $people, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/drive-links/'.$this->drive->registration_token.'/group-registrations', [
            'organisation_name' => 'UM Matina Red Cross Youth',
            'contact_name' => 'Liza Santos',
            'contact_phone' => '+639181112222',
            'consent' => true,
            'people' => $people,
            ...$overrides,
        ]);
    }

    private function roster(?User $as = null, ?MobileEvent $drive = null): TestResponse
    {
        return $this->actingAs($as ?? $this->staff)->getJson('/api/blood-center/drives/'.($drive ?? $this->drive)->id.'/registrations');
    }

    // --- The link -------------------------------------------------------------

    public function test_the_link_shows_the_drive_and_nobodys_details(): void
    {
        DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id, 'email' => 'someone@example.com']);

        $response = $this->getJson("/api/drive-links/{$this->drive->registration_token}")
            ->assertOk()
            ->assertJsonPath('drive.name', $this->drive->name)
            ->assertJsonPath('drive.centre_name', $this->facility->name)
            ->assertJsonPath('drive.state', 'open')
            ->assertJsonMissingPath('drive.id')
            // No health questions on the link (project owner, 2026-11-12).
            ->assertJsonMissingPath('self_check');

        $this->assertStringNotContainsString('someone@example.com', $response->getContent());
    }

    public function test_a_draft_or_unknown_link_is_not_found(): void
    {
        $draft = MobileEvent::factory()->draft()->create(['facility_id' => $this->facility->id]);

        $this->getJson("/api/drive-links/{$draft->registration_token}")->assertNotFound();
        $this->getJson('/api/drive-links/'.str_repeat('a', 32))->assertNotFound()->assertJsonPath('code', 'drive_link_not_found');
        $this->registerSelf([], $draft)->assertNotFound();
        $this->assertSame(0, DrivePreRegistration::query()->count());
    }

    public function test_every_drive_gets_its_own_link_token(): void
    {
        $other = MobileEvent::factory()->create();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $this->drive->registration_token);
        $this->assertNotSame($this->drive->registration_token, $other->registration_token);
    }

    // --- A person registering themselves ----------------------------------------

    public function test_a_person_without_an_account_puts_themselves_on_the_list(): void
    {
        $users = User::query()->count();

        $this->registerSelf(['self_check' => ['age' => true]])
            ->assertCreated()
            ->assertJsonPath('message', "You are on the list for {$this->drive->name}.")
            ->assertJsonMissingPath('needs_review');

        $entry = DrivePreRegistration::query()->sole();
        $this->assertSame(PreRegistrationSource::Individual, $entry->source);
        $this->assertSame(PreRegistrationStatus::PreRegistered, $entry->status);
        $this->assertSame('ana.reyes@example.com', $entry->email);
        $this->assertSame('+639171234567', $entry->phone);
        $this->assertNotNull($entry->consent_at);

        // A planning record only: no account, booking or donation comes of it.
        $this->assertSame($users, User::query()->count());
        $this->assertSame(0, DonationAppointment::query()->count());
        $this->assertSame(0, Donation::query()->count());

        $log = AuditLog::query()->where('action', 'drive.pre_registered')->sole();
        $this->assertNull($log->actor_id);
        $this->assertSame($this->facility->id, $log->facility_id);
    }

    public function test_a_name_one_contact_and_consent_are_required(): void
    {
        $this->registerSelf(['phone' => null, 'email' => null])->assertUnprocessable()->assertJsonValidationErrors(['phone', 'email']);
        $this->registerSelf(['phone' => '0821234567', 'email' => null])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->registerSelf(['first_name' => 'Ana2'])->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->registerSelf(['consent' => false])->assertUnprocessable()->assertJsonValidationErrors('consent');

        // Either contact alone is enough; birth date and affiliation are optional.
        $this->registerSelf(['phone' => null, 'birth_date' => null, 'affiliation' => null])->assertCreated();
        $this->registerSelf(['email' => null, 'phone' => '+639991112233'])->assertCreated();
    }

    public function test_the_same_person_is_listed_once_and_answered_as_anyone_is(): void
    {
        $first = $this->registerSelf()->assertCreated()->json();
        $again = $this->registerSelf(['email' => 'ANA.REYES@example.com', 'phone' => null, 'first_name' => 'Annie'])->assertCreated()->json();
        $byPhone = $this->registerSelf(['email' => null, 'phone' => '+639171234567'])->assertCreated()->json();

        $this->assertSame($first, $again);
        $this->assertSame($first, $byPhone);
        $this->assertSame(1, DrivePreRegistration::query()->count());
    }

    public function test_someone_taken_off_the_list_can_register_again(): void
    {
        DrivePreRegistration::factory()->withdrawn()->create([
            'mobile_event_id' => $this->drive->id,
            'email' => 'ana.reyes@example.com',
            'phone' => '+639171234567',
        ]);

        $this->registerSelf()->assertCreated();

        $this->assertSame(1, DrivePreRegistration::query()->onList()->count());
    }

    public function test_the_link_has_no_limit_and_raises_the_pre_registered_count(): void
    {
        foreach (range(1, 5) as $i) {
            $this->registerSelf(['email' => "person{$i}@example.com", 'phone' => null])->assertCreated();
        }

        $this->roster()->assertOk()->assertJsonPath('summary.pre_registered_donors', 5);
        $this->actingAs($this->staff)->getJson('/api/blood-center/drives')->assertOk()->assertJsonPath('drives.0.pre_registered_count', 5);
    }

    public function test_a_closed_cancelled_or_held_drive_takes_no_registrations(): void
    {
        $cases = [
            'registration_closed' => ['registration_closed_at' => now()],
            'drive_cancelled' => ['cancelled_at' => now(), 'cancellation_reason' => 'Typhoon'],
            'drive_completed' => ['event_date' => now()->subWeek()->toDateString()],
        ];

        foreach ($cases as $code => $state) {
            $drive = MobileEvent::factory()->create(['facility_id' => $this->facility->id, ...$state]);

            $this->registerSelf([], $drive)->assertStatus(409)->assertJsonPath('code', $code);
            $this->getJson("/api/drive-links/{$drive->registration_token}")
                ->assertOk()
                ->assertJsonPath('drive.state', ['registration_closed' => 'closed', 'drive_cancelled' => 'cancelled', 'drive_completed' => 'completed'][$code]);
        }

        $this->assertSame(0, DrivePreRegistration::query()->count());
    }

    public function test_the_link_is_rate_limited_by_address(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->registerSelf(['email' => "person{$i}@example.com", 'phone' => null])->assertCreated();
        }

        $this->registerSelf(['email' => 'one.more@example.com', 'phone' => null])->assertTooManyRequests();
    }

    // --- A partner's list -----------------------------------------------------

    public function test_a_partner_puts_its_people_on_the_list_in_one_batch(): void
    {
        DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id, 'email' => 'already@example.com', 'phone' => null]);

        $this->registerGroup([
            ['first_name' => 'Ben', 'last_name' => 'Cruz', 'email' => 'ben@example.com'],
            ['first_name' => 'Already', 'last_name' => 'There', 'email' => 'ALREADY@example.com'],
            ['first_name' => 'Ben', 'last_name' => 'Cruz', 'email' => 'Ben@Example.com'],
            ['first_name' => 'Carla', 'last_name' => 'Dizon', 'phone' => '09185556666', 'affiliation' => 'Section B'],
        ])
            ->assertCreated()
            ->assertJsonPath('received', 4);

        $submission = DrivePartnerSubmission::query()->sole();
        $this->assertSame('UM Matina Red Cross Youth', $submission->organisation_name);
        $this->assertSame('+639181112222', $submission->contact_phone);

        $added = DrivePreRegistration::query()->where('source', 'partner')->get();
        $this->assertCount(2, $added);
        $this->assertTrue($added->every(fn (DrivePreRegistration $entry): bool => $entry->partner_submission_id === $submission->id
            && $entry->status === PreRegistrationStatus::PreRegistered));
        $this->assertSame('+639185556666', $added->firstWhere('first_name', 'Carla')->phone);

        $log = AuditLog::query()->where('action', 'drive.group_submitted')->sole();
        $this->assertSame(2, $log->context['rows']);
        $this->assertSame(2, $log->context['skipped']);
        $this->assertSame(0, DonationAppointment::query()->count());
    }

    public function test_each_partner_entry_meets_the_rules_a_person_registering_meets(): void
    {
        $this->registerGroup([['first_name' => 'Ben', 'last_name' => 'Cruz']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['people.0.phone', 'people.0.email']);

        $this->registerGroup([['first_name' => 'Ben', 'last_name' => 'Cruz', 'email' => 'ben@example.com']], ['contact_phone' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contact_phone');

        $this->registerGroup([['first_name' => 'Ben', 'last_name' => 'Cruz', 'email' => 'ben@example.com']], ['consent' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('consent');

        config(['donation.drive_group_max_people' => 2]);
        $this->registerGroup(array_map(fn (int $i): array => ['first_name' => 'Ben', 'last_name' => 'Cruz', 'email' => "ben{$i}@example.com"], [1, 2, 3]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('people');

        $this->assertSame(0, DrivePreRegistration::query()->count());
    }

    public function test_a_partner_reads_nothing_back_through_the_link(): void
    {
        DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id, 'email' => 'private@example.com']);

        $response = $this->registerGroup([['first_name' => 'Ben', 'last_name' => 'Cruz', 'email' => 'ben@example.com']])->assertCreated();

        $this->assertStringNotContainsString('private@example.com', $response->getContent());
        $this->assertSame(['message', 'drive_name', 'received'], array_keys($response->json()));
        // Nor is there anything to read without a staff sign-in.
        $this->getJson("/api/blood-center/drives/{$this->drive->id}/registrations")->assertUnauthorized();
    }

    // --- Staff tidy the list -----------------------------------------------------

    public function test_the_roster_is_the_callers_own_centre_only(): void
    {
        $theirs = MobileEvent::factory()->create();

        $this->roster(null, $theirs)->assertNotFound();

        $phlebotomist = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create();
        $this->roster($phlebotomist)->assertForbidden();
    }

    public function test_staff_link_unlink_and_withdraw_an_entry(): void
    {
        $entry = DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id]);
        $donor = User::factory()->donor()->create();
        $base = "/api/blood-center/drives/{$this->drive->id}/registrations/{$entry->id}";

        $this->actingAs($this->staff)->postJson("{$base}/match", ['donor_uuid' => $donor->uuid])
            ->assertOk()
            ->assertJsonPath('data.donor_id', $donor->id);
        $this->actingAs($this->staff)->deleteJson("{$base}/match")->assertOk()->assertJsonPath('data.donor_id', null);

        $this->actingAs($this->staff)->postJson("{$base}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->actingAs($this->staff)->postJson("{$base}/withdraw")->assertStatus(409)->assertJsonPath('code', 'already_withdrawn');
        $this->actingAs($this->staff)->postJson("{$base}/match", ['donor_uuid' => $donor->uuid])->assertStatus(409);

        foreach (['matched', 'unmatched', 'withdrawn'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => "drive.pre_registration_{$action}", 'actor_id' => $this->staff->id]);
        }
    }

    public function test_linking_a_donor_who_already_booked_counts_them_once(): void
    {
        $donor = User::factory()->donor()->create(['email' => 'different@example.com']);
        $booking = DonationAppointment::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => $this->facility->id,
            'event_id' => $this->drive->id,
            'appointment_datetime' => $this->drive->event_date->copy()->setTime(9, 0),
        ]);
        $entry = DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id, 'email' => 'listed@example.com']);

        $this->roster()->assertJsonPath('summary.pre_registered_donors', 2);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$this->drive->id}/registrations/{$entry->id}/match", ['donor_uuid' => $donor->uuid])
            ->assertOk()
            ->assertJsonPath('data.appointment_id', $booking->id);

        $this->roster()->assertJsonPath('summary.pre_registered_donors', 1);
    }

    public function test_an_entry_on_another_drive_or_an_unknown_donor_is_refused(): void
    {
        $elsewhere = DrivePreRegistration::factory()->create(['mobile_event_id' => MobileEvent::factory()->create(['facility_id' => $this->facility->id])->id]);
        $entry = DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id]);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$this->drive->id}/registrations/{$elsewhere->id}/withdraw")
            ->assertNotFound();

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$this->drive->id}/registrations/{$entry->id}/match", ['donor_uuid' => '00000000-0000-4000-8000-000000000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('donor_uuid');
    }

    public function test_the_drives_list_carries_the_link_and_the_live_count(): void
    {
        DrivePreRegistration::factory()->count(2)->create(['mobile_event_id' => $this->drive->id]);
        DrivePreRegistration::factory()->withdrawn()->create(['mobile_event_id' => $this->drive->id]);
        $draft = MobileEvent::factory()->draft()->create(['facility_id' => $this->facility->id]);

        $drives = collect($this->actingAs($this->staff)->getJson('/api/blood-center/drives')->assertOk()->json('drives'))->keyBy('id');

        $this->assertSame(2, $drives[$this->drive->id]['pre_registered_count']);
        $this->assertSame($this->drive->registration_token, $drives[$this->drive->id]['registration_token']);
        $this->assertNull($drives[$draft->id]['registration_token']);
    }

    // --- Cancellation and retention ---------------------------------------------

    public function test_cancelling_the_drive_tells_the_people_on_its_list(): void
    {
        DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id, 'email' => 'ana@example.com']);
        DrivePreRegistration::factory()->fromPartner()->create(['mobile_event_id' => $this->drive->id, 'email' => null, 'phone' => '+639170000000']);
        DrivePreRegistration::factory()->withdrawn()->create(['mobile_event_id' => $this->drive->id, 'email' => 'gone@example.com']);
        // Texts on, written to the log rather than sent.
        config(['services.semaphore.log_only' => true]);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$this->drive->id}/cancel", ['reason' => 'Venue flooded'])
            ->assertOk();

        Notification::assertSentOnDemandTimes(DriveCancelledForRegistrant::class, 2);
        Notification::assertSentOnDemand(
            DriveCancelledForRegistrant::class,
            fn (DriveCancelledForRegistrant $notice, array $channels, AnonymousNotifiable $to): bool => ($to->routes['mail'] ?? null) === 'ana@example.com'
                && in_array('mail', $channels, true)
        );
        Notification::assertSentOnDemand(
            DriveCancelledForRegistrant::class,
            fn (DriveCancelledForRegistrant $notice, array $channels, AnonymousNotifiable $to): bool => ($to->routes['sms'] ?? null) === '+639170000000'
                && $channels === [SmsChannel::class]
        );

        $this->assertSame(2, AuditLog::query()->where('action', 'drive.cancelled')->sole()->context['registrants_notified']);
    }

    public function test_without_texts_only_the_people_with_an_email_are_told(): void
    {
        DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id, 'email' => 'ana@example.com']);
        DrivePreRegistration::factory()->create(['mobile_event_id' => $this->drive->id, 'email' => null, 'phone' => '+639170000000']);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/drives/{$this->drive->id}/cancel", ['reason' => 'Venue flooded'])
            ->assertOk();

        Notification::assertSentOnDemandTimes(DriveCancelledForRegistrant::class, 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'drive.cancelled')->sole()->context['registrants_notified']);
    }

    public function test_unconverted_entries_are_anonymised_ninety_days_after_their_drive(): void
    {
        $old = MobileEvent::factory()->create(['facility_id' => $this->facility->id, 'event_date' => now()->subDays(91)->toDateString()]);
        $recent = MobileEvent::factory()->create(['facility_id' => $this->facility->id, 'event_date' => now()->subDays(30)->toDateString()]);

        $stale = DrivePreRegistration::factory()->fromPartner()->create(['mobile_event_id' => $old->id]);
        $booked = DrivePreRegistration::factory()->create([
            'mobile_event_id' => $old->id,
            'appointment_id' => DonationAppointment::factory()->create([
                'facility_id' => $this->facility->id,
                'event_id' => $old->id,
                'status' => AppointmentStatus::Completed,
            ])->id,
        ]);
        $kept = DrivePreRegistration::factory()->create(['mobile_event_id' => $recent->id]);

        $this->artisan('drives:anonymise-pre-registrations')->assertSuccessful();

        $stale->refresh();
        $this->assertNotNull($stale->anonymised_at);
        $this->assertNull($stale->first_name);
        $this->assertNull($stale->email);
        $this->assertNull($stale->phone);
        $this->assertNull(DrivePartnerSubmission::query()->find($stale->partner_submission_id)->contact_name);
        $this->assertNotNull($booked->fresh()->first_name);
        $this->assertNotNull($kept->fresh()->first_name);

        // The rows, and so the drive's figures, stay.
        $this->roster(null, $old)->assertOk()->assertJsonPath('summary.pre_registered_donors', 2);

        $this->assertDatabaseHas('audit_logs', ['action' => 'drive.pre_registrations_anonymised', 'auditable_id' => $old->id]);

        // A second run finds nothing more to do.
        $this->artisan('drives:anonymise-pre-registrations')->expectsOutput('Anonymised 0 drive pre-registration(s).');
    }
}
