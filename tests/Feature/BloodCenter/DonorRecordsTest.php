<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AccountStatus;
use App\Enums\DonationStatus;
use App\Enums\IdentityStatus;
use App\Enums\StaffRole;
use App\Mail\DonorCenterEmail;
use App\Models\AuditLog;
use App\Models\BloodType;
use App\Models\Donation;
use App\Models\DonationScreening;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\FacilityDonor;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Donors page: the list, a centre's own record of a donor, the export,
 * and the emails a centre sends.
 *
 * What is locked in: a donor bled this morning is never "eligible", so never
 * reminded; a centre's status and notes stay with that centre; staff cannot
 * use a "correction" to take over a donor's account; and the reminder reaches
 * exactly the donors the confirmation counted.
 */
class DonorRecordsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();

        $this->facility = Facility::factory()->approved()->create([
            'name' => 'Lifestream Blood Center',
            'email' => 'donors@lifestream.test',
            'phone' => '(032) 555 0101',
        ]);
        $this->receptionist = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
    }

    /**
     * A donor whose last donation here was drawn on the given day.
     */
    private function donorWhoGave(string $on, DonationStatus $status = DonationStatus::Completed, ?Facility $at = null): User
    {
        $donor = User::factory()->donor()->create();

        Donation::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => ($at ?? $this->facility)->id,
            'donation_date' => $on,
            'status' => $status,
        ]);

        return $donor;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $donor, array $query = []): array
    {
        $rows = collect(
            $this->actingAs($this->receptionist)
                ->getJson('/api/blood-center/donors?'.http_build_query($query))
                ->assertOk()
                ->json('data')
        );

        $row = $rows->firstWhere('uuid', $donor->uuid);
        $this->assertNotNull($row, 'The donor is not on the list.');

        return $row;
    }

    private function walkInPayload(array $overrides = []): array
    {
        return [
            'first_name' => 'Mohit',
            'last_name' => 'Chandra',
            'valid_id_number' => 'N01-23-456789',
            'birth_date' => now()->subYears(28)->toDateString(),
            'gender' => 'male',
            'phone' => '09171234567',
            'email' => 'mohit@example.test',
            'address' => 'House 42, Cebu City',
            'occupation' => 'Engineer',
            'status' => 'active',
            'notes' => 'Prefers morning slots.',
            ...$overrides,
        ];
    }

    // --- The list --------------------------------------------------------------

    public function test_a_walk_in_registered_here_is_on_the_list_straight_away(): void
    {
        $uuid = $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donors', $this->walkInPayload())
            ->assertCreated()
            ->assertJsonPath('data.restricted', false)
            ->assertJsonPath('data.registered_here', true)
            ->assertJsonPath('data.occupation', 'Engineer')
            ->assertJsonPath('data.notes', 'Prefers morning slots.')
            ->json('data.uuid');

        $row = $this->row(User::where('uuid', $uuid)->firstOrFail());

        $this->assertSame('male', $row['gender']);
        $this->assertSame('House 42, Cebu City', $row['address']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('eligible', $row['eligibility']);
    }

    public function test_a_donor_bled_this_morning_is_waiting_not_eligible(): void
    {
        // Collected, still in the laboratory: not completed. Counting completed
        // donations alone called this donor eligible.
        $donor = $this->donorWhoGave(now()->toDateTimeString(), DonationStatus::Collected);

        $row = $this->row($donor);

        $this->assertSame('waiting', $row['eligibility']);
        $this->assertSame(1, $row['total_donations']);
        $this->assertSame(now()->addDays((int) config('donation.interval_days'))->toDateString(), $row['next_eligible_date']);
    }

    public function test_a_declared_last_donation_counts_when_none_is_on_record(): void
    {
        $donor = User::factory()->donor()->create();
        DonorProfile::where('donor_id', $donor->id)->update(['last_donation_date' => now()->subDays(10)->toDateString()]);
        FacilityDonor::create(['facility_id' => $this->facility->id, 'donor_id' => $donor->id, 'registered_here' => true]);

        $row = $this->row($donor);

        $this->assertSame('waiting', $row['eligibility']);
        $this->assertTrue($row['last_donation_declared']);
        $this->assertSame(0, $row['total_donations']);
    }

    public function test_a_standing_deferral_shows_as_deferred_with_its_kind(): void
    {
        $donor = $this->donorWhoGave(now()->subYear()->toDateTimeString());
        DonationScreening::factory()->permanentlyDeferred()->create([
            'donation_id' => Donation::where('donor_id', $donor->id)->value('id'),
            'facility_id' => $this->facility->id,
            'screened_at' => now()->subYear(),
        ]);

        $row = $this->row($donor);

        $this->assertSame('deferred', $row['eligibility']);
        $this->assertSame('permanent', $row['deferral']['kind']);
        $this->assertArrayNotHasKey('deferral_reason', $row['deferral']);
    }

    public function test_a_temporary_deferral_holds_until_the_donor_is_screened_again(): void
    {
        $donor = User::factory()->donor()->create();
        $visit = Donation::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => $this->facility->id,
            'donation_date' => now()->subMonths(6),
            'status' => DonationStatus::Rejected,
        ]);
        DonationScreening::factory()->deferred()->create([
            'donation_id' => $visit->id,
            'facility_id' => $this->facility->id,
            'screened_at' => now()->subMonths(6),
        ]);

        $this->assertSame('temporary', $this->row($donor)['deferral']['kind']);

        $next = Donation::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => $this->facility->id,
            'donation_date' => now()->subMonths(5),
        ]);
        DonationScreening::factory()->create([
            'donation_id' => $next->id,
            'facility_id' => $this->facility->id,
            'screened_at' => now()->subMonths(5),
        ]);

        $this->assertSame('eligible', $this->row($donor)['eligibility']);
    }

    public function test_the_list_searches_by_full_name_donor_code_and_local_phone(): void
    {
        $donor = $this->donorWhoGave(now()->subYear()->toDateTimeString());
        $donor->forceFill(['first_name' => 'Nandini', 'last_name' => 'Bose', 'phone' => '+639181112222'])->save();
        $other = $this->donorWhoGave(now()->subYear()->toDateTimeString());

        foreach (['nandini bose', 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT), '0918111'] as $term) {
            $uuids = collect($this->actingAs($this->receptionist)
                ->getJson('/api/blood-center/donors?search='.urlencode($term))
                ->assertOk()
                ->json('data'))->pluck('uuid');

            $this->assertContains($donor->uuid, $uuids, "Searching [{$term}]");
            $this->assertNotContains($other->uuid, $uuids, "Searching [{$term}]");
        }
    }

    public function test_status_is_this_centres_own_and_filters_the_list(): void
    {
        $donor = $this->donorWhoGave(now()->subYear()->toDateTimeString());
        $elsewhere = Facility::factory()->approved()->create();
        Donation::factory()->create(['donor_id' => $donor->id, 'facility_id' => $elsewhere->id, 'donation_date' => now()->subYears(2)]);

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$donor->uuid}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $inactive = collect($this->actingAs($this->receptionist)->getJson('/api/blood-center/donors?status=inactive')->json('data'))->pluck('uuid');
        $active = collect($this->actingAs($this->receptionist)->getJson('/api/blood-center/donors?status=active')->json('data'))->pluck('uuid');

        $this->assertContains($donor->uuid, $inactive);
        $this->assertNotContains($donor->uuid, $active);

        // The other centre still sees an active donor.
        $theirStaff = User::factory()->bloodCenterStaff($elsewhere, StaffRole::MedicalReceptionist)->create();
        $theirs = collect($this->actingAs($theirStaff)->getJson('/api/blood-center/donors')->json('data'))->firstWhere('uuid', $donor->uuid);
        $this->assertSame('active', $theirs['status']);
    }

    // --- Editing ---------------------------------------------------------------

    public function test_the_counter_corrects_a_blood_type_and_the_change_is_traced(): void
    {
        $donor = $this->donorWhoGave(now()->subYear()->toDateTimeString());
        $from = $donor->donorProfile->bloodType->code;
        $to = BloodType::query()->where('code', '!=', $from)->first() ?? BloodType::factory()->create();

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$donor->uuid}", ['blood_type_id' => $to->id, 'occupation' => 'Teacher'])
            ->assertOk()
            ->assertJsonPath('data.blood_type', $to->code)
            ->assertJsonPath('data.occupation', 'Teacher');

        $log = AuditLog::where('action', 'donor.updated')->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing(['blood_type_id', 'occupation'], $log->context['fields']);
        $this->assertSame(['from' => $from, 'to' => $to->code], $log->context['blood_type']);
    }

    public function test_a_verified_identity_keeps_its_name_and_birth_date(): void
    {
        $donor = $this->donorWhoGave(now()->subYear()->toDateTimeString());
        DonorProfile::where('donor_id', $donor->id)->update(['identity_status' => IdentityStatus::Verified->value]);

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$donor->uuid}", ['first_name' => 'Someone Else'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('first_name');

        $this->assertNotSame('Someone Else', $donor->fresh()->first_name);
    }

    public function test_staff_cannot_change_the_email_of_a_donor_who_owns_their_account(): void
    {
        // A portal donor: active, verified. Changing their email here, then a
        // password reset to it, would hand the account to someone else.
        $donor = $this->donorWhoGave(now()->subYear()->toDateTimeString());

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$donor->uuid}", ['email' => 'attacker@example.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertNotSame('attacker@example.test', $donor->fresh()->email);
    }

    public function test_staff_correct_the_contact_details_of_an_unclaimed_walk_in_they_registered(): void
    {
        $uuid = $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donors', $this->walkInPayload())
            ->assertCreated()
            ->assertJsonPath('data.editable.contact', true)
            ->json('data.uuid');

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$uuid}", ['email' => 'mohit.c@example.test', 'phone' => '0917 765 4321'])
            ->assertOk()
            ->assertJsonPath('data.email', 'mohit.c@example.test')
            ->assertJsonPath('data.phone', '+639177654321');

        $this->assertNull(User::where('uuid', $uuid)->value('email_verified_at'));
    }

    public function test_any_centre_corrects_a_donor_but_not_what_the_donor_keeps(): void
    {
        // Donor Management is global (project owner, 2026-10-25); the locks on
        // a verified identity and the donor's own contact details still hold.
        $theirs = $this->donorWhoGave(now()->subYear()->toDateTimeString(), at: Facility::factory()->approved()->create());

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$theirs->uuid}", ['occupation' => 'Nurse'])
            ->assertOk();

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$theirs->uuid}", ['email' => 'someone.else@example.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_a_photo_is_set_for_a_walk_in_but_not_for_a_portal_donor(): void
    {
        Storage::fake('local');

        $uuid = $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donors', $this->walkInPayload())
            ->json('data.uuid');

        $this->actingAs($this->receptionist)
            ->post("/api/blood-center/donors/{$uuid}/photo", ['photo' => UploadedFile::fake()->image('face.jpg')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['photo_url']);

        $portal = $this->donorWhoGave(now()->subYear()->toDateTimeString());

        $this->actingAs($this->receptionist)
            ->post("/api/blood-center/donors/{$portal->uuid}/photo", ['photo' => UploadedFile::fake()->image('face.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');
    }

    // --- Export ----------------------------------------------------------------

    public function test_the_export_is_a_spreadsheet_safe_csv_without_a_city_column(): void
    {
        $donor = $this->donorWhoGave(now()->subYear()->toDateTimeString());
        $donor->forceFill(['first_name' => '=HYPERLINK("x")', 'phone' => '+639171234567'])->save();
        FacilityDonor::create(['facility_id' => $this->facility->id, 'donor_id' => $donor->id, 'notes' => '+ needs a chair']);

        $response = $this->actingAs($this->receptionist)->get('/api/blood-center/donors/export')->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        // Parsed as a spreadsheet would, so a quoted line break inside an
        // address stays inside its cell.
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $header = fgetcsv($stream, escape: '');
        $row = array_combine($header, fgetcsv($stream, escape: ''));
        fclose($stream);

        $this->assertNotContains('City', $header);
        $this->assertContains('Address', $header);
        $this->assertSame('0917 123 4567', $row['Phone']);
        $this->assertStringStartsWith("'=", $row['FullName']);
        $this->assertStringStartsWith("'+", $row['Notes']);
        $this->assertSame('1', $row['TotalDonations']);
        $this->assertSame('None', $row['DeferralStatus']);
        $this->assertSame('Active', $row['Status']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'donors.exported', 'actor_id' => $this->receptionist->id]);
    }

    public function test_the_export_needs_the_full_record(): void
    {
        $chair = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create();

        $this->actingAs($chair)->get('/api/blood-center/donors/export', ['Accept' => 'application/json'])->assertForbidden();
    }

    // --- Eligibility reminders ---------------------------------------------------

    public function test_reminders_reach_exactly_the_donors_the_preview_counted(): void
    {
        $eligible = $this->donorWhoGave(now()->subYear()->toDateTimeString());
        $waiting = $this->donorWhoGave(now()->subDays(5)->toDateTimeString());

        $optedOut = $this->donorWhoGave(now()->subYear()->toDateTimeString());
        DonorProfile::where('donor_id', $optedOut->id)->update(['notification_preferences' => ['eligibility_renewal' => false]]);

        $inactive = $this->donorWhoGave(now()->subYear()->toDateTimeString());
        FacilityDonor::create(['facility_id' => $this->facility->id, 'donor_id' => $inactive->id, 'status' => 'inactive']);

        $noEmail = $this->donorWhoGave(now()->subYear()->toDateTimeString());
        $noEmail->forceFill(['email' => null])->save();

        $this->actingAs($this->receptionist)
            ->getJson('/api/blood-center/donors/eligibility-reminders')
            ->assertOk()
            ->assertJsonPath('recipients', 1)
            ->assertJsonPath('skipped.not_yet_eligible', 1)
            ->assertJsonPath('skipped.opted_out', 1)
            ->assertJsonPath('skipped.no_email', 1);

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donors/eligibility-reminders')
            ->assertOk()
            ->assertJsonPath('sent', 1);

        Mail::assertQueued(DonorCenterEmail::class, 1);
        Mail::assertQueued(DonorCenterEmail::class, fn (DonorCenterEmail $mail): bool => $mail->hasTo($eligible->email)
            && $mail->hasFrom(config('mail.from.address'), 'Lifestream Blood Center')
            && $mail->hasReplyTo('donors@lifestream.test')
            && str_contains($mail->subjectLine, 'Lifestream Blood Center'));

        foreach ([$waiting, $optedOut, $inactive] as $skipped) {
            Mail::assertNotQueued(DonorCenterEmail::class, fn (DonorCenterEmail $mail): bool => $mail->hasTo($skipped->email));
        }
    }

    public function test_a_donor_in_a_visit_not_yet_drawn_is_in_progress_not_eligible(): void
    {
        // The counter refuses a second visit over this one; the list must agree.
        $donor = User::factory()->donor()->create();
        $visit = Donation::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => $this->facility->id,
            'donation_date' => now(),
            'status' => DonationStatus::Registered,
        ]);

        $row = $this->row($donor);

        $this->assertSame('in_progress', $row['eligibility']);
        $this->assertSame($visit->id, $row['open_visit_id']);

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donors/eligibility-reminders')
            ->assertJsonPath('sent', 0)
            ->assertJsonPath('skipped.in_progress', 1);

        // Once the visit is closed they are eligible again.
        $visit->forceFill(['status' => DonationStatus::Rejected])->save();
        $this->assertSame('eligible', $this->row($donor)['eligibility']);
    }

    public function test_a_second_click_does_not_mail_the_same_donors_again(): void
    {
        $this->donorWhoGave(now()->subYear()->toDateTimeString());

        $this->actingAs($this->receptionist)->postJson('/api/blood-center/donors/eligibility-reminders')->assertJsonPath('sent', 1);
        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donors/eligibility-reminders')
            ->assertJsonPath('sent', 0)
            ->assertJsonPath('skipped.recently_reminded', 1);

        Mail::assertQueued(DonorCenterEmail::class, 1);
    }

    public function test_reminders_follow_the_blood_group_filter(): void
    {
        $match = $this->donorWhoGave(now()->subYear()->toDateTimeString());
        $this->donorWhoGave(now()->subYear()->toDateTimeString());

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donors/eligibility-reminders?blood_type_id='.$match->donorProfile->blood_type_id)
            ->assertJsonPath('sent', 1);

        Mail::assertQueued(DonorCenterEmail::class, fn (DonorCenterEmail $mail): bool => $mail->hasTo($match->email));
    }

    // --- One donor's email -------------------------------------------------------

    public function test_a_thank_you_needs_a_donation_at_this_centre(): void
    {
        $uuid = $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donors', $this->walkInPayload())
            ->json('data.uuid');

        $this->actingAs($this->receptionist)
            ->postJson("/api/blood-center/donors/{$uuid}/email", ['template' => 'thank_you'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'no_donation_here');

        $donor = $this->donorWhoGave(now()->subDays(3)->toDateTimeString());

        $this->actingAs($this->receptionist)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/email", ['template' => 'thank_you'])
            ->assertOk();

        Mail::assertQueued(DonorCenterEmail::class, fn (DonorCenterEmail $mail): bool => $mail->hasTo($donor->email)
            && str_contains(implode(' ', $mail->paragraphs), now()->subDays(3)->format('j F Y')));
    }

    public function test_a_deferred_donor_is_never_reminded_to_donate(): void
    {
        $donor = $this->donorWhoGave(now()->subYear()->toDateTimeString());
        DonationScreening::factory()->indefinitelyDeferred()->create([
            'donation_id' => Donation::where('donor_id', $donor->id)->value('id'),
            'facility_id' => $this->facility->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/email", ['template' => 'eligibility_reminder'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'donor_deferred');

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/donors/eligibility-reminders')
            ->assertJsonPath('sent', 0)
            ->assertJsonPath('skipped.deferred', 1);

        Mail::assertNothingQueued();
    }

    // --- Templates ---------------------------------------------------------------

    public function test_the_admin_rewords_a_template_and_the_next_email_uses_it(): void
    {
        $admin = User::factory()->bloodCenterAdmin($this->facility)->create();

        $this->actingAs($admin)
            ->putJson('/api/blood-center/facility/email-templates/eligibility_reminder', [
                'subject' => 'Hi {first_name}, we miss you at {center_name}',
                'body' => "Dear {first_name},\n\nCome back from {next_eligible_date}.\n\n{unknown}",
            ])
            ->assertOk()
            ->assertJsonPath('templates.1.customised', true);

        $donor = $this->donorWhoGave(now()->subYear()->toDateTimeString());

        $this->actingAs($this->receptionist)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/email", ['template' => 'eligibility_reminder'])
            ->assertOk();

        Mail::assertQueued(DonorCenterEmail::class, fn (DonorCenterEmail $mail): bool => $mail->subjectLine === "Hi {$donor->first_name}, we miss you at Lifestream Blood Center"
            && $mail->paragraphs === ["Dear {$donor->first_name},", 'Come back from today.', '{unknown}']);

        $this->actingAs($admin)
            ->deleteJson('/api/blood-center/facility/email-templates/eligibility_reminder')
            ->assertOk()
            ->assertJsonPath('templates.1.customised', false);
    }

    public function test_only_the_admin_edits_the_templates(): void
    {
        $this->actingAs($this->receptionist)
            ->putJson('/api/blood-center/facility/email-templates/thank_you', ['subject' => 'x', 'body' => 'y'])
            ->assertForbidden();
    }

    public function test_the_email_renders_with_the_centres_name_and_no_markup_from_the_template(): void
    {
        $mail = new DonorCenterEmail(
            centerName: 'Lifestream Blood Center',
            centerEmail: 'donors@lifestream.test',
            centerPhone: null,
            centerAddress: 'Cebu City',
            subjectLine: 'Thank you',
            paragraphs: ['<script>alert(1)</script> Thank you.'],
        );

        $html = $mail->render();

        $this->assertStringContainsString('Lifestream Blood Center', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('RedAgos', $html);
    }

    public function test_a_suspended_account_is_not_emailed(): void
    {
        $donor = $this->donorWhoGave(now()->subDays(3)->toDateTimeString());
        $donor->forceFill(['account_status' => AccountStatus::Suspended])->save();

        $this->actingAs($this->receptionist)
            ->postJson("/api/blood-center/donors/{$donor->uuid}/email", ['template' => 'thank_you'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'account_closed');
    }
}
