<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\Donation;
use App\Models\DonationScreening;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\DonorDeferred;
use App\Support\OperationalDay;
use App\Support\ScreeningVitals;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\RecordsCounterVitals;
use Tests\TestCase;

/**
 * The counter's vital signs and its deferral form (project owner, 2026-10-24).
 *
 * What is locked in: all six readings before accepting or deferring; a
 * vital-sign code (R2-Low, R2-High, R7, R8) only while a reading is out of
 * range, haemoglobin by sex and the stricter range when sex is not recorded;
 * history codes and Other whatever the readings; and a temporary deferral at
 * the counter ends on its Until date.
 */
class CounterVitalsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RecordsCounterVitals;

    private Facility $facility;

    private User $physician;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
    }

    private function visitOf(?string $gender = null): Donation
    {
        $donor = User::factory()->donor()->create();
        $donor->donorProfile->update(['gender' => $gender]);

        return Donation::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => $this->facility->id,
            'status' => DonationStatus::Registered,
            'donation_date' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function screen(Donation $visit, array $payload)
    {
        return $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$visit->id}/screening", $payload);
    }

    public function test_all_six_readings_come_before_accepting_or_deferring(): void
    {
        $visit = $this->visitOf('female');

        $this->screen($visit, ['outcome' => 'accepted', 'systolic_bp' => 118])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['diastolic_bp', 'pulse_bpm', 'temperature_c', 'weight_kg', 'haemoglobin_g_dl'])
            ->assertJsonMissingValidationErrors('systolic_bp');

        $this->screen($visit, ['outcome' => 'temporarily_deferred', 'deferral_code' => 'R1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(ScreeningVitals::fields());

        $this->screen($visit, $this->withVitals(['outcome' => 'accepted']))->assertCreated();
    }

    public function test_normal_readings_allow_a_history_code_or_other_but_no_vital_sign_code(): void
    {
        foreach (['R2-Low', 'R2-High', 'R7', 'R8'] as $code) {
            $this->screen($this->visitOf('male'), $this->withVitals(['outcome' => 'temporarily_deferred', 'deferral_code' => $code]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('deferral_code');
        }

        $this->screen($this->visitOf('male'), $this->withVitals(['outcome' => 'temporarily_deferred', 'deferral_code' => 'R1']))->assertCreated();
        $this->screen($this->visitOf('male'), $this->withVitals(['outcome' => 'permanently_deferred', 'other_reason' => 'Specialist advice']))->assertCreated();
    }

    public function test_a_reading_out_of_range_unlocks_its_code(): void
    {
        $cases = [
            'R2-High' => ['haemoglobin_g_dl' => 18.0],
            'R7' => ['systolic_bp' => 170],
            'R8' => ['weight_kg' => 45],
        ];

        foreach ($cases as $code => $reading) {
            $this->screen($this->visitOf('female'), $this->withVitals(['outcome' => 'temporarily_deferred', 'deferral_code' => $code, ...$reading]))
                ->assertCreated()
                ->assertJsonPath('data.screening.deferral_code', $code);
        }
    }

    public function test_haemoglobin_is_judged_by_sex_and_by_the_stricter_range_when_unrecorded(): void
    {
        $low = $this->withVitals(['outcome' => 'temporarily_deferred', 'deferral_code' => 'R2-Low', 'haemoglobin_g_dl' => 13.0]);

        // 13.0 is low for a man (13.5) but normal for a woman (12.5).
        $this->screen($this->visitOf('male'), $low)->assertCreated();
        $this->screen($this->visitOf('female'), $low)->assertUnprocessable()->assertJsonValidationErrors('deferral_code');
        $this->screen($this->visitOf('prefer_not_to_say'), $low)->assertCreated();
    }

    public function test_the_counter_is_sent_the_ranges_for_this_donor(): void
    {
        $male = $this->screen($this->visitOf('male'), $this->withVitals(['outcome' => 'accepted']))->assertCreated();
        $female = $this->screen($this->visitOf('female'), $this->withVitals(['outcome' => 'accepted']))->assertCreated();

        $this->assertEquals(13.5, $male->json('data.vitals.ranges.haemoglobin_g_dl.min'));
        $this->assertEquals(12.5, $female->json('data.vitals.ranges.haemoglobin_g_dl.min'));
        $this->assertEquals(17.5, $female->json('data.vitals.ranges.haemoglobin_g_dl.max'));
        $this->assertSame('R2-Low', $female->json('data.vitals.ranges.haemoglobin_g_dl.low_code'));
        $this->assertContains('R7', $female->json('data.vitals.vital_only_codes'));
    }

    public function test_a_temporary_deferral_at_the_counter_ends_on_its_until_date(): void
    {
        $visit = $this->visitOf('female');
        $until = OperationalDay::today()->addDays(10)->toDateString();

        $this->screen($visit, $this->withVitals(['outcome' => 'temporarily_deferred', 'deferral_code' => 'R1', 'deferred_until' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deferred_until');

        $this->screen($visit, $this->withVitals(['outcome' => 'temporarily_deferred', 'deferral_code' => 'R1', 'deferred_until' => $until]))
            ->assertCreated()
            ->assertJsonPath('data.screening.deferred_until', $until);

        $donor = User::findOrFail($visit->donor_id);

        $this->actingAs($donor)->getJson('/api/donors/deferral')
            ->assertOk()
            ->assertJsonPath('deferred', true)
            ->assertJsonPath('until', $until);

        Notification::assertSentTo($donor, DonorDeferred::class, function (DonorDeferred $notification) use ($donor): bool {
            return str_starts_with((string) $notification->toDatabase($donor)['meta'], 'Deferred until ');
        });

        $this->travelTo(OperationalDay::today()->addDays(12)->setTime(12, 0));

        $this->actingAs($donor)->getJson('/api/donors/deferral')
            ->assertOk()
            ->assertJsonPath('deferred', false);
    }

    public function test_an_until_date_is_kept_only_for_a_temporary_deferral(): void
    {
        $visit = $this->visitOf('female');

        $this->screen($visit, $this->withVitals([
            'outcome' => 'permanently_deferred',
            'deferral_code' => 'R12',
            'deferred_until' => OperationalDay::today()->addDays(10)->toDateString(),
        ]))->assertCreated();

        $this->assertNull(DonationScreening::where('donation_id', $visit->id)->value('deferred_until'));
    }
}
