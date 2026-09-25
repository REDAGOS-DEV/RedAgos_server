<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\DonationStatus;
use App\Enums\ScreeningOutcome;
use App\Models\Donation;
use App\Models\DonationScreening;
use App\Models\EligibilityQuestion;
use App\Models\EligibilityScreening;
use App\Models\EligibilityScreeningAnswer;
use App\Models\Facility;
use App\Models\User;
use Database\Seeders\EligibilityQuestionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Section I-D of the DOH questionnaire, as the counter records it.
 *
 * Everything here belongs to the screening officer: the four boxes they ask
 * the donor in person, the four findings they observe, and the REMARKS box
 * they tick. None of it is donor-facing, and none of it is pre-filled from
 * what the donor declared days earlier in Sections I-A to I-C.
 */
class PhysicalExaminationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $officer;

    private User $donor;

    private int $donationId;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->officer = User::factory()->bloodCenterStaff($this->facility, Department::Collection)->create();
        $this->donor = User::factory()->donor()->create();

        $this->donationId = Donation::factory()->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->facility->id,
            'status' => DonationStatus::Registered,
        ])->id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function examination(array $overrides = []): array
    {
        return array_merge([
            'outcome' => 'accepted',

            // Asked in person, before anything is measured.
            'sleep' => '7 hours',
            'meal' => 'Breakfast at 7am',
            'meds' => 'None',
            'allergies' => 'None known',

            'systolic_bp' => 118,
            'diastolic_bp' => 76,
            'pulse_bpm' => 72,
            'temperature_c' => 36.6,
            'weight_kg' => 65,
            'haemoglobin_g_dl' => 14.2,

            // Observed.
            'general_appearance' => 'Well, ambulatory',
            'skin' => 'No lesions or puncture marks',
            'heent' => 'Unremarkable',
            'heart_and_lungs' => 'Clear, regular rhythm',
        ], $overrides);
    }

    private function record(array $overrides = [])
    {
        return $this->actingAs($this->officer)
            ->postJson("/api/blood-center/donations/{$this->donationId}/screening", $this->examination($overrides));
    }

    // --- The eight new fields -----------------------------------------------

    public function test_the_whole_examination_round_trips(): void
    {
        $this->record()
            ->assertCreated()
            ->assertJsonPath('data.screening.sleep', '7 hours')
            ->assertJsonPath('data.screening.meal', 'Breakfast at 7am')
            ->assertJsonPath('data.screening.meds', 'None')
            ->assertJsonPath('data.screening.allergies', 'None known')
            ->assertJsonPath('data.screening.general_appearance', 'Well, ambulatory')
            ->assertJsonPath('data.screening.skin', 'No lesions or puncture marks')
            ->assertJsonPath('data.screening.heent', 'Unremarkable')
            ->assertJsonPath('data.screening.heart_and_lungs', 'Clear, regular rhythm');
    }

    public function test_an_examination_with_none_of_them_still_records(): void
    {
        // What a centre writes down varies, and a blank is more honest than a
        // required field filled with a placeholder.
        $this->actingAs($this->officer)
            ->postJson("/api/blood-center/donations/{$this->donationId}/screening", ['outcome' => 'accepted'])
            ->assertCreated();

        $screening = DonationScreening::where('donation_id', $this->donationId)->firstOrFail();

        $this->assertNull($screening->general_appearance);
        $this->assertNull($screening->sleep);
    }

    public function test_a_field_tabbed_past_is_stored_as_absent_not_as_an_empty_string(): void
    {
        $this->record(['skin' => '   '])->assertCreated();

        $this->assertNull(DonationScreening::where('donation_id', $this->donationId)->value('skin'));
    }

    public function test_the_screening_officer_is_the_authenticated_user_and_cannot_be_sent(): void
    {
        $impostor = User::factory()->bloodCenterStaff($this->facility, Department::Collection)->create();

        $this->record(['recorded_by' => $impostor->id, 'facility_id' => 9999])->assertCreated();

        $screening = DonationScreening::where('donation_id', $this->donationId)->firstOrFail();

        $this->assertSame($this->officer->id, $screening->recorded_by);
        $this->assertSame($this->facility->id, $screening->facility_id);
    }

    public function test_the_officers_name_comes_back_for_the_signature_line(): void
    {
        $this->record()
            ->assertCreated()
            ->assertJsonPath(
                'data.screening.recorded_by',
                trim($this->officer->first_name.' '.$this->officer->last_name)
            );
    }

    // --- The officer's meds field is not the donor's questionnaire answer ----

    public function test_the_officers_meds_field_is_independent_of_the_donors_own_answer(): void
    {
        $this->seed(EligibilityQuestionSeeder::class);

        // The donor declared, days earlier and in the app, that they take no
        // medication.
        $screening = EligibilityScreening::factory()->create([
            'donor_id' => $this->donor->id,
            'question_version' => 2,
            'gender_at_screening' => 'male',
        ]);

        EligibilityScreeningAnswer::create([
            'screening_id' => $screening->id,
            'question_code' => 'v2_ay_2',
            'answer' => false,
            'created_at' => now(),
        ]);

        // The officer asks at the counter and is told otherwise. Both records
        // stand: a disagreement between them is itself a finding, which is why
        // they live in separate tables with separate authors.
        $this->record(['meds' => 'Paracetamol this morning'])->assertCreated();

        $this->assertSame(
            'Paracetamol this morning',
            DonationScreening::where('donation_id', $this->donationId)->value('meds')
        );

        $this->assertFalse(
            EligibilityScreeningAnswer::where('screening_id', $screening->id)
                ->where('question_code', 'v2_ay_2')
                ->value('answer'),
            "The officer's finding overwrote the donor's own declaration."
        );
    }

    public function test_the_donor_has_no_route_that_writes_the_examination(): void
    {
        // Section I-D is the officer's. Nothing on the donor side of the API
        // may reach it, and a donor posting to the counter's own endpoint is
        // refused by role before any of this is considered.
        $this->actingAs($this->donor)
            ->postJson("/api/blood-center/donations/{$this->donationId}/screening", $this->examination())
            ->assertForbidden();

        $this->assertSame(0, DonationScreening::count());
    }

    // --- The four REMARKS outcomes ------------------------------------------

    public function test_accepted_lets_the_donor_proceed(): void
    {
        $this->record(['outcome' => 'accepted'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'screening')
            ->assertJsonPath('data.screening.outcome_label', 'Accepted');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function deferralOutcomes(): array
    {
        return [
            'temporary' => ['temporarily_deferred', 'Temporarily Deferred'],
            'permanent' => ['permanently_deferred', 'Permanently Deferred'],
            'indefinite' => ['indefinite_deferral', 'Indefinite Deferral'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deferralOutcomes')]
    public function test_every_deferral_ends_the_visit_the_same_way(string $outcome, string $label): void
    {
        // The three differ in what the donor is told and what the next counter
        // sees. Inside the donation workflow they are one thing.
        $this->record(['outcome' => $outcome, 'deferral_reason' => 'Recorded by the officer.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.screening.outcome', $outcome)
            ->assertJsonPath('data.screening.outcome_label', $label)
            ->assertJsonPath('data.rejection_reason', 'Recorded by the officer.');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deferralOutcomes')]
    public function test_every_deferral_requires_a_reason(string $outcome): void
    {
        // The old rule compared against one value, so a permanent deferral
        // would have slipped through unexplained.
        $this->record(['outcome' => $outcome, 'deferral_reason' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('deferral_reason');
    }

    public function test_an_accepted_outcome_keeps_no_deferral_reason(): void
    {
        $this->record(['outcome' => 'accepted', 'deferral_reason' => 'Left over from a mis-click.'])
            ->assertCreated();

        $this->assertNull(DonationScreening::where('donation_id', $this->donationId)->value('deferral_reason'));
    }

    public function test_the_superseded_outcome_values_are_refused(): void
    {
        foreach (['qualified', 'deferred'] as $old) {
            $this->record(['outcome' => $old])
                ->assertStatus(422)
                ->assertJsonValidationErrors('outcome');
        }
    }

    public function test_the_enum_labels_every_case_it_accepts(): void
    {
        // label() is an exhaustive match with no default, so a case added
        // without one is a fatal error at the first screening rather than a
        // wrong label on a clinical record.
        foreach (ScreeningOutcome::cases() as $case) {
            $this->assertNotSame('', $case->label());
        }
    }
}
