<?php

namespace Tests\Feature\Donor;

use App\Enums\AppointmentStatus;
use App\Models\DonationAppointment;
use App\Models\DonorQrToken;
use App\Models\EligibilityQuestion;
use App\Models\EligibilityScreening;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\ScreeningWindowOpen;
use App\Support\AppointmentScreeningWindow;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Database\Seeders\EligibilityQuestionSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A donor books freely and answers the questionnaire the day before.
 *
 * The window opens at 00:00 the day before and closes at the end of the
 * appointment day. The close is end-of-day rather than the booked time on
 * purpose: a donor who never answered has no QR, so the remedy is to fill it in
 * at the counter -- and a window that shut at the booked time would be shut for
 * exactly the person who needs it.
 */
class ScreeningWindowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $donor;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(EligibilityQuestionSeeder::class);

        $this->facility = Facility::factory()->approved()->create();
        $this->donor = User::factory()->donor()->create();
        $this->donor->donorProfile->update([
            'birth_date' => now()->subYears(30)->toDateString(),
            'gender' => 'male',
        ]);
    }

    private function bookFor(Carbon $when): DonationAppointment
    {
        return DonationAppointment::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'appointment_datetime' => $when,
            'status' => AppointmentStatus::Scheduled,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'question_version' => 2,
            'answers' => EligibilityQuestion::forVersion(2)
                ->get()
                ->filter(fn (EligibilityQuestion $q): bool => $q->appliesToGender('male'))
                ->map(fn (EligibilityQuestion $q): array => [
                    'code' => $q->code,
                    'answer' => $q->disqualify_if_answer === null ? false : ! $q->disqualify_if_answer,
                ])
                ->values()->all(),
            'consent' => ['version' => config('donor_consent.current'), 'accepted' => true],
            'vitals' => ['weight' => 65],
        ];
    }

    // --- The bounds ---------------------------------------------------------

    public function test_the_window_opens_the_day_before_and_closes_at_the_end_of_the_day(): void
    {
        $appointment = $this->bookFor(Carbon::parse('2026-10-15 09:00', config('blood_center.timezone')));

        $this->assertSame('2026-10-14 00:00:00', AppointmentScreeningWindow::opensAt($appointment)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 23:59:59', AppointmentScreeningWindow::closesAt($appointment)->format('Y-m-d H:i:s'));
    }

    public function test_the_questionnaire_is_refused_before_the_window_opens(): void
    {
        $appointment = $this->bookFor(now()->addWeeks(3));

        // Refused at the form, not only at submission: a donor who opens it
        // three weeks early should be told the date, not allowed to answer
        // thirty questions that cannot be counted.
        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/questions')
            ->assertStatus(409)
            ->assertJsonPath('code', 'screening_window_not_open')
            ->assertJsonPath('window_opens_on', AppointmentScreeningWindow::opensOn($appointment));

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'screening_window_not_open');

        $this->assertSame(0, EligibilityScreening::count());
    }

    public function test_the_questionnaire_opens_the_day_before(): void
    {
        $this->bookFor(now()->addDay()->setTime(9, 0));

        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/questions')
            ->assertOk();

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();
    }

    public function test_the_questionnaire_is_still_open_on_the_day_itself(): void
    {
        $this->bookFor(now()->setTime(9, 0));

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();
    }

    public function test_a_donor_arriving_late_can_still_answer(): void
    {
        // Booked for 09:00, arriving at 16:00. The remedy for a missed
        // questionnaire is to fill it in at the counter, so the window must not
        // have shut at the booked time.
        $this->travelTo(now()->setTime(16, 0));
        $this->bookFor(now()->setTime(9, 0));

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();
    }

    public function test_a_donor_with_no_appointment_is_not_subject_to_the_window(): void
    {
        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/questions')
            ->assertOk();

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();
    }

    public function test_the_window_is_computed_in_the_operational_timezone(): void
    {
        // config/app.php defaults to UTC while deployment runs in Manila, and
        // under UTC Manila's 00:00-08:00 still reads as the previous date. A
        // window computed from a bare now() opens eight hours late for every
        // Manila donor -- and passes a UTC test suite, which is why this test
        // pins the timezone rather than trusting the ambient one.
        config(['app.timezone' => 'UTC', 'blood_center.timezone' => 'Asia/Manila']);

        $appointment = $this->bookFor(Carbon::parse('2026-10-15 09:00', 'Asia/Manila'));

        $opens = AppointmentScreeningWindow::opensAt($appointment);

        $this->assertSame('Asia/Manila', $opens->timezone->getName());
        $this->assertSame('2026-10-14 00:00:00', $opens->format('Y-m-d H:i:s'));
    }

    // --- The credential is capped to the visit ------------------------------

    public function test_the_qr_expires_with_the_appointment_rather_than_in_a_fortnight(): void
    {
        $appointment = $this->bookFor(now()->addDay()->setTime(9, 0));

        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();

        // Fourteen days would outlive the visit it was minted for.
        $this->assertSame(
            AppointmentScreeningWindow::closesAt($appointment)->toDateString(),
            $response->json('qr_valid_until')
        );
    }

    public function test_a_walk_in_questionnaire_keeps_the_full_fortnight(): void
    {
        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();

        $this->assertSame(now()->addDays(14)->toDateString(), $response->json('qr_valid_until'));
    }

    // --- Moving or dropping the appointment ---------------------------------

    public function test_rescheduling_out_of_the_window_revokes_the_credential(): void
    {
        // Booked far enough out that the 24-hour change window still allows a
        // move, then travelled into the screening window so the questionnaire
        // can be answered against it.
        $appointment = $this->bookFor(now()->addDays(5)->setTime(9, 0));
        $this->travelTo(now()->addDays(4)->setTime(1, 0));

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();

        $this->assertSame(1, DonorQrToken::usable()->count());

        $this->actingAs($this->donor)
            ->patchJson('/api/donors/appointments/'.$appointment->id, [
                'type' => 'walkin',
                'center_id' => $this->facility->id,
                'date' => now()->addWeeks(3)->toDateString(),
                'time_slot' => '09:00',
            ])
            ->assertOk()
            ->assertJsonPath('requires_new_screening', true);

        // Otherwise the donor walks in a fortnight later holding a working code
        // backed by answers given for a visit that never happened.
        $this->assertSame(0, DonorQrToken::usable()->count());
    }

    public function test_cancelling_revokes_the_credential(): void
    {
        $appointment = $this->bookFor(now()->addDays(5)->setTime(9, 0));
        $this->travelTo(now()->addDays(4)->setTime(1, 0));

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();

        $this->actingAs($this->donor)
            ->deleteJson('/api/donors/appointments/'.$appointment->id)
            ->assertOk();

        $this->assertSame(0, DonorQrToken::usable()->count());
    }

    // --- The reminder -------------------------------------------------------

    public function test_the_reminder_goes_to_a_donor_who_has_not_answered(): void
    {
        $this->bookFor(now()->addDay()->setTime(9, 0));

        $this->artisan('donors:open-screening-window')->assertSuccessful();

        Notification::assertSentTo($this->donor, ScreeningWindowOpen::class);
    }

    public function test_the_reminder_is_withheld_from_a_donor_who_has_already_answered(): void
    {
        $this->bookFor(now()->addDay()->setTime(9, 0));

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();

        $this->artisan('donors:open-screening-window')->assertSuccessful();

        Notification::assertNothingSentTo($this->donor);
    }

    public function test_a_donor_booked_weeks_out_is_not_reminded_yet(): void
    {
        $this->bookFor(now()->addWeeks(3));

        $this->artisan('donors:open-screening-window')->assertSuccessful();

        Notification::assertNothingSentTo($this->donor);
    }

    public function test_the_reminder_is_mailed_and_filed_for_a_donor_with_an_address(): void
    {
        $this->bookFor(now()->addDay()->setTime(9, 0));

        $this->artisan('donors:open-screening-window')->assertSuccessful();

        Notification::assertSentTo(
            $this->donor,
            ScreeningWindowOpen::class,
            function (ScreeningWindowOpen $notification, array $channels): bool {
                return in_array('mail', $channels, true)
                    && in_array('database', $channels, true);
            }
        );
    }

    public function test_a_donor_with_no_email_gets_the_in_app_copy_only(): void
    {
        $this->donor->forceFill(['email' => null, 'email_verified_at' => null])->save();
        $this->bookFor(now()->addDay()->setTime(9, 0));

        $this->artisan('donors:open-screening-window')->assertSuccessful();

        Notification::assertSentTo(
            $this->donor,
            ScreeningWindowOpen::class,
            fn (ScreeningWindowOpen $n, array $channels): bool => $channels === ['database']
        );
    }

    public function test_the_reminder_command_is_on_the_schedule(): void
    {
        // The command existing is not the command running, and with the booking
        // gate gone nothing else catches a donor who books and forgets.
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e): bool => str_contains((string) $e->command, 'donors:open-screening-window'));

        $this->assertNotNull($event, 'donors:open-screening-window is not registered in routes/console.php.');
        $this->assertSame(config('blood_center.timezone'), $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    /**
     * A day is compared as two instants, never as a date taken from a timestamp.
     *
     * This is the failure that hides until the clock crosses into it. The test
     * suite runs with APP_TIMEZONE=UTC while the operational timezone is
     * Asia/Manila, so between 16:00 and midnight UTC the two disagree about
     * what day it is — and `whereDate()` on a timestamp column then misses
     * every appointment booked for "today". It passed for months and began
     * failing at 03:00 Manila.
     */
    public function test_an_operational_day_is_bounded_by_instants_not_by_a_date_string(): void
    {
        config(['app.timezone' => 'UTC', 'blood_center.timezone' => 'Asia/Manila']);

        [$start, $end] = OperationalDay::boundsFor('2026-10-15');

        // Manila's 15 October runs from 16:00 on the 14th UTC to 15:59 on the
        // 15th. Anything narrower drops eight hours of real appointments.
        $this->assertSame('2026-10-14 16:00:00', $start->toDateTimeString());
        $this->assertSame('2026-10-15 15:59:59', $end->toDateTimeString());
    }

    public function test_an_appointment_booked_during_the_offset_is_still_found_for_today(): void
    {
        config(['app.timezone' => 'UTC', 'blood_center.timezone' => 'Asia/Manila']);

        // 02:00 Manila on the appointment day, which is 18:00 UTC the day
        // before — squarely inside the window where the two disagree.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 02:00', 'Asia/Manila'));

        $appointment = $this->bookFor(Carbon::parse('2026-10-15 09:00', 'Asia/Manila'));

        [$start, $end] = OperationalDay::boundsFor(OperationalDay::todayAsDate());

        $this->assertTrue(
            CarbonImmutable::parse($appointment->appointment_datetime)->between($start, $end),
            "An appointment booked for today falls outside today's own bounds."
        );
    }
}
