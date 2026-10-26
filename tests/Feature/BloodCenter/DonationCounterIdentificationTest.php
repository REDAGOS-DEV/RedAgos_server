<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\AppointmentStatus;
use App\Enums\EligibilityStatus;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\DonationAppointment;
use App\Models\DonorQrToken;
use App\Models\EligibilityQuestion;
use App\Models\EligibilityScreening;
use App\Models\EligibilityScreeningAnswer;
use App\Models\Facility;
use App\Models\FacilityDonor;
use App\Models\MobileEvent;
use App\Models\User;
use Database\Seeders\EligibilityQuestionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Donation counter's two ways in.
 *
 * A booked donor is scanned: the desk sees the booking and prints the
 * questionnaire they answered in the Donor Portal. A walk-in is found by name,
 * email or phone, and answers the questionnaire onsite on the counter's device,
 * because a donor registered at the counter has no sign-in to answer it with.
 */
class DonationCounterIdentificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $receptionist;

    private User $physician;

    private User $chair;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(EligibilityQuestionSeeder::class);

        $this->facility = Facility::factory()->approved()->create();
        $this->receptionist = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
        $this->physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $this->chair = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create();
    }

    private function donor(array $attributes = [], string $gender = 'male'): User
    {
        $donor = User::factory()->donor()->create($attributes);
        $donor->donorProfile->update([
            'gender' => $gender,
            'birth_date' => now()->subYears(30)->toDateString(),
        ]);

        return $donor->fresh();
    }

    /** On this centre's list, the way a walk-in registered at its counter is. */
    private function registeredHere(User $donor): User
    {
        FacilityDonor::create([
            'facility_id' => $this->facility->id,
            'donor_id' => $donor->id,
            'registered_here' => true,
        ]);

        return $donor;
    }

    private function answeredInPortal(User $donor): EligibilityScreening
    {
        $version = (int) config('donation.questionnaire_version');

        $screening = EligibilityScreening::factory()->create([
            'donor_id' => $donor->id,
            'question_version' => $version,
            'result' => EligibilityStatus::Pending,
            'computed_result' => 'review',
            'gender_at_screening' => 'male',
            'consented_at' => now(),
            'consent_version' => config('donor_consent.current'),
        ]);

        EligibilityQuestion::forVersion($version)
            ->get()
            ->filter(fn (EligibilityQuestion $q): bool => $q->appliesToGender('male'))
            ->each(fn (EligibilityQuestion $q) => EligibilityScreeningAnswer::create([
                'screening_id' => $screening->id,
                'question_code' => $q->code,
                // Every risk question answered the way that trips its marker,
                // so a flag would show if the print leaked one.
                'answer' => $q->disqualify_if_answer ?? false,
                'created_at' => now(),
            ]));

        return $screening->fresh();
    }

    /**
     * A full set of answers to the current questionnaire, as the counter's form sends it.
     *
     * @return array<string, mixed>
     */
    private function counterPayload(string $gender = 'male'): array
    {
        $version = (int) config('donation.questionnaire_version');

        return [
            'question_version' => $version,
            'answers' => EligibilityQuestion::forVersion($version)
                ->get()
                ->filter(fn (EligibilityQuestion $q): bool => $q->isRequiredForGender($gender))
                ->map(fn (EligibilityQuestion $q): array => ['code' => $q->code, 'answer' => false])
                ->values()
                ->all(),
            'consent' => ['version' => config('donor_consent.current'), 'accepted' => true],
            'vitals' => ['weight' => 65],
        ];
    }

    private function questionnaireUrl(User $donor, string $suffix = ''): string
    {
        return "/api/blood-center/donors/{$donor->uuid}/health-questionnaire{$suffix}";
    }

    // --- QR scan: the booking -----------------------------------------------

    public function test_the_scan_shows_the_booking_the_donor_made_in_the_portal(): void
    {
        $donor = $this->donor();
        $screening = $this->answeredInPortal($donor);
        $drive = MobileEvent::factory()->create(['facility_id' => $this->facility->id, 'name' => 'Ateneo Blood Drive', 'location' => 'Gym']);

        DonationAppointment::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => $this->facility->id,
            'event_id' => $drive->id,
            'appointment_datetime' => now(),
            'status' => AppointmentStatus::Scheduled,
        ]);

        $raw = Str::random(40);
        DonorQrToken::factory()->create([
            'donor_id' => $donor->id,
            'screening_id' => $screening->id,
            'token_hash' => hash('sha256', $raw),
            'issued_at' => now(),
            'expires_at' => now()->addDays(14),
        ]);

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $raw])
            ->assertOk()
            ->assertJsonPath('data.appointment.event.name', 'Ateneo Blood Drive')
            ->assertJsonPath('data.appointment.event.location', 'Gym')
            ->assertJsonPath('data.health_questionnaire.completed_via', 'donor_portal')
            ->assertJsonStructure(['data' => ['appointment' => ['booked_at']]]);
    }

    // --- QR scan: printing the completed questionnaire ----------------------

    public function test_the_desk_prints_the_completed_questionnaire_without_review_markers(): void
    {
        $donor = $this->registeredHere($this->donor());
        $screening = $this->answeredInPortal($donor);

        $response = $this->actingAs($this->receptionist)
            ->getJson($this->questionnaireUrl($donor, '/print').'?screening_id='.$screening->id)
            ->assertOk()
            ->assertJsonPath('data.screening_id', $screening->id)
            ->assertJsonPath('data.completed_via', 'donor_portal')
            ->assertJsonPath('data.facility.name', $this->facility->name)
            ->assertJsonMissingPath('data.computed_assessment')
            ->assertJsonMissingPath('data.flagged_codes');

        foreach ($response->json('data.sections') as $section) {
            foreach ($section['questions'] as $question) {
                $this->assertArrayNotHasKey('flagged', $question);
                $this->assertArrayHasKey('answer_label', $question);
            }
        }

        $this->assertTrue(AuditLog::query()
            ->where('action', 'collection.questionnaire_printed')
            ->where('actor_id', $this->receptionist->id)
            ->exists());
    }

    public function test_the_receptionist_still_cannot_open_the_physicians_review(): void
    {
        $donor = $this->registeredHere($this->donor());
        $this->answeredInPortal($donor);

        $this->actingAs($this->receptionist)
            ->getJson($this->questionnaireUrl($donor))
            ->assertForbidden();
    }

    public function test_the_chair_cannot_print_the_questionnaire(): void
    {
        $donor = $this->registeredHere($this->donor());
        $this->answeredInPortal($donor);

        $this->actingAs($this->chair)
            ->getJson($this->questionnaireUrl($donor, '/print'))
            ->assertForbidden();
    }

    public function test_a_donor_who_has_not_presented_here_cannot_be_printed(): void
    {
        $donor = $this->donor();
        $this->answeredInPortal($donor);

        $this->actingAs($this->receptionist)
            ->getJson($this->questionnaireUrl($donor, '/print'))
            ->assertForbidden()
            ->assertJsonPath('code', 'donor_not_presented');
    }

    // --- Walk-in: the onsite questionnaire ----------------------------------

    public function test_the_counter_serves_the_walk_in_the_questions_the_app_would(): void
    {
        $donor = $this->registeredHere($this->donor([], 'female'));

        $response = $this->actingAs($this->receptionist)
            ->getJson($this->questionnaireUrl($donor, '/form'))
            ->assertOk()
            ->assertJsonPath('data.version', (int) config('donation.questionnaire_version'))
            ->assertJsonPath('data.donor.gender', 'female')
            ->assertJsonPath('data.consent.version', config('donor_consent.current'))
            ->assertJsonPath('data.current.available', false);

        $served = collect($response->json('data.sections'))->flatMap(fn (array $s): array => $s['questions']);
        $this->assertSame(
            EligibilityQuestion::forVersion((int) config('donation.questionnaire_version'))->count(),
            $served->count()
        );

        // No disqualification marker reaches whoever holds the device.
        $this->assertStringNotContainsString('disqualify_if_answer', $response->getContent());
    }

    public function test_a_walk_in_answers_onsite_and_the_physician_reads_it(): void
    {
        $donor = $this->registeredHere($this->donor());

        $response = $this->actingAs($this->receptionist)
            ->postJson($this->questionnaireUrl($donor), $this->counterPayload())
            ->assertCreated()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.completed_via', 'counter')
            ->assertJsonPath('data.consent_captured', true);

        // The desk gets the reference back, never the answers.
        $this->assertStringNotContainsString('answer_label', $response->getContent());

        $screening = EligibilityScreening::query()->where('donor_id', $donor->id)->sole();
        $this->assertSame($this->receptionist->id, $screening->recorded_by);
        $this->assertTrue(AuditLog::query()->where('action', 'eligibility.screening.recorded_at_counter')->exists());

        $this->actingAs($this->physician)
            ->getJson($this->questionnaireUrl($donor))
            ->assertOk()
            ->assertJsonPath('data.completed_via', 'counter')
            ->assertJsonPath(
                'data.notice',
                'Declared by the donor at the counter, on a staff device. This is not the on-site screening.'
            );
    }

    public function test_the_onsite_questionnaire_is_held_to_the_apps_rules(): void
    {
        $donor = $this->registeredHere($this->donor());
        $payload = $this->counterPayload();
        array_pop($payload['answers']);

        $this->actingAs($this->receptionist)
            ->postJson($this->questionnaireUrl($donor), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('answers');

        $this->actingAs($this->receptionist)
            ->postJson($this->questionnaireUrl($donor), [...$this->counterPayload(), 'consent' => ['version' => config('donor_consent.current')]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('consent.accepted');

        $this->assertSame(0, EligibilityScreening::query()->count());
    }

    public function test_answering_again_onsite_supersedes_the_portal_credential(): void
    {
        $donor = $this->registeredHere($this->donor());
        $old = $this->answeredInPortal($donor);
        $old->update(['question_version' => 1]);

        $token = DonorQrToken::factory()->create([
            'donor_id' => $donor->id,
            'screening_id' => $old->id,
            'token_hash' => hash('sha256', Str::random(40)),
            'issued_at' => now(),
            'expires_at' => now()->addDays(14),
        ]);

        $this->actingAs($this->receptionist)
            ->postJson($this->questionnaireUrl($donor), $this->counterPayload())
            ->assertCreated();

        $this->assertNotNull($token->fresh()->revoked_at);
    }

    public function test_the_chair_cannot_take_the_questionnaire_and_nobody_can_for_an_absent_donor(): void
    {
        $here = $this->registeredHere($this->donor());

        $this->actingAs($this->chair)
            ->postJson($this->questionnaireUrl($here), $this->counterPayload())
            ->assertForbidden();

        $absent = $this->donor();

        $this->actingAs($this->receptionist)
            ->postJson($this->questionnaireUrl($absent), $this->counterPayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'donor_not_presented');
    }

    // --- Walk-in: finding the donor -----------------------------------------

    public function test_a_walk_in_is_found_here_by_part_of_their_name(): void
    {
        $ana = $this->registeredHere($this->donor(['first_name' => 'Ana', 'last_name' => 'Cruz', 'phone' => '+639171234567', 'email' => 'ana.cruz@example.com']));
        $this->registeredHere($this->donor(['first_name' => 'Ben', 'last_name' => 'Santos']));

        $response = $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/collection/donor-search', ['q' => 'ana cr'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $ana->uuid)
            ->assertJsonPath('data.0.scope', 'own_facility')
            ->assertJsonPath('data.0.phone_hint', '•••• 4567')
            ->assertJsonPath('data.0.email_hint', 'a•••@example.com')
            ->assertJsonPath('truncated', false);

        // Masked: the full number and address never leave in a search.
        $this->assertStringNotContainsString('9171234567', $response->getContent());
        $this->assertStringNotContainsString('ana.cruz@', $response->getContent());
    }

    public function test_a_name_never_reaches_another_centres_donor(): void
    {
        $this->donor(['first_name' => 'Carla', 'last_name' => 'Reyes']);

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/collection/donor-search', ['q' => 'Carla Reyes'])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_another_centres_donor_is_found_by_their_exact_email_or_phone(): void
    {
        $carla = $this->donor(['first_name' => 'Carla', 'last_name' => 'Reyes', 'email' => 'carla@example.com', 'phone' => '+639181112222']);

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/collection/donor-search', ['q' => 'carla@example.com'])
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $carla->uuid)
            ->assertJsonPath('data.0.scope', 'cross_facility')
            ->assertJsonPath('data.0.matched_by', 'email');

        // Typed the local way, as a donor reads it out.
        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/collection/donor-search', ['q' => '0918 111 2222'])
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $carla->uuid)
            ->assertJsonPath('data.0.matched_by', 'phone');

        // Part of the address is not enough across centres.
        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/collection/donor-search', ['q' => 'carla@'])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_choosing_a_result_tells_the_counter_whether_a_questionnaire_is_on_file(): void
    {
        $donor = $this->registeredHere($this->donor());
        $code = 'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT);

        $this->actingAs($this->physician)
            ->getJson("/api/blood-center/donors/lookup?type=donor_code&value={$code}")
            ->assertOk()
            ->assertJsonPath('health_questionnaire.available', false);

        $this->actingAs($this->receptionist)
            ->postJson($this->questionnaireUrl($donor), $this->counterPayload())
            ->assertCreated();

        $response = $this->actingAs($this->physician)
            ->getJson("/api/blood-center/donors/lookup?type=donor_code&value={$code}")
            ->assertOk()
            ->assertJsonPath('health_questionnaire.available', true)
            ->assertJsonPath('health_questionnaire.completed_via', 'counter');

        $this->assertStringNotContainsString('answer_label', $response->getContent());
    }

    public function test_the_search_wants_two_characters_and_keeps_no_term_in_the_audit(): void
    {
        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/collection/donor-search', ['q' => 'a'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/collection/donor-search', ['q' => 'Dolores'])
            ->assertOk();

        $entry = AuditLog::query()->where('action', 'donor.searched_at_counter')->sole();
        $this->assertStringNotContainsString('Dolores', json_encode($entry->toArray()));
    }

    /**
     * The search runs as staff type. On the shared `throttle:N,1` counter a
     * busy minute of it would refuse the questionnaire a donor just answered.
     */
    public function test_searching_as_you_type_never_spends_the_onsite_questionnaires_budget(): void
    {
        $donor = $this->registeredHere($this->donor());

        foreach (range(1, 40) as $i) {
            $this->actingAs($this->receptionist)
                ->postJson('/api/blood-center/collection/donor-search', ['q' => "An{$i}"])
                ->assertOk();
        }

        $this->actingAs($this->receptionist)
            ->postJson($this->questionnaireUrl($donor), $this->counterPayload())
            ->assertCreated();
    }

    public function test_only_the_counter_may_search(): void
    {
        $billing = User::factory()->bloodCenterStaff($this->facility, StaffRole::BillingClerk)->create();

        $this->actingAs($billing)
            ->postJson('/api/blood-center/collection/donor-search', ['q' => 'Ana'])
            ->assertForbidden();

        $this->actingAs($this->chair)
            ->postJson('/api/blood-center/collection/donor-search', ['q' => 'Ana'])
            ->assertOk();
    }
}
