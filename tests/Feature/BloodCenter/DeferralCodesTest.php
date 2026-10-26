<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\DonationStatus;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\DeferralCode;
use App\Models\Donation;
use App\Models\DonationScreening;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\DonorDeferred;
use App\Support\OperationalDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\RecordsCounterVitals;
use Tests\TestCase;

/**
 * Deferral codes (project owner, 2026-10-24): a deferral is recorded by code,
 * and the record shows the code, so a donor who sees it learns nothing more.
 *
 * What is locked in: each centre's list is seeded once and kept by the
 * Screening Physician alone; the reason reaches only staff allowed the
 * clinical record; a retired code is not offered; a code on a record is never
 * renamed, and rewording one leaves past records as they were.
 */
class DeferralCodesTest extends TestCase
{
    use LazilyRefreshDatabase;
    use RecordsCounterVitals;

    private Facility $facility;

    private User $physician;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->physician = User::factory()->bloodCenterStaff($this->facility, StaffRole::ScreeningPhysician)->create();
        $this->receptionist = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
    }

    /**
     * Screen a fresh visit at the counter as deferred under a code, or under
     * Other with the reason typed. A haemoglobin code comes with a reading
     * that suggests it, as the counter requires.
     *
     * @param  array<string, mixed>  $readings
     */
    private function deferAtCounter(?string $code, ?string $other = null, array $readings = [])
    {
        $readings = [
            ...match ($code) {
                'R2-Low', 'r2-low' => ['haemoglobin_g_dl' => 11.0],
                'R2-High' => ['haemoglobin_g_dl' => 19.0],
                default => [],
            },
            ...$readings,
        ];

        $donation = Donation::factory()->create([
            'donor_id' => User::factory()->donor()->create()->id,
            'facility_id' => $this->facility->id,
            'status' => DonationStatus::Registered,
            'donation_date' => now(),
        ]);

        return $this->actingAs($this->physician)
            ->postJson("/api/blood-center/donations/{$donation->id}/screening", $this->withVitals(array_filter([
                'outcome' => 'temporarily_deferred',
                'deferral_code' => $code,
                'other_reason' => $other,
                ...$readings,
            ], fn ($value): bool => $value !== null)));
    }

    // --- The list --------------------------------------------------------------

    public function test_a_centres_first_look_seeds_its_list_once(): void
    {
        $response = $this->actingAs($this->physician)
            ->getJson('/api/blood-center/deferral-codes')
            ->assertOk()
            ->assertJsonCount(count(DeferralCode::DEFAULTS), 'data')
            ->assertJsonPath('data.0.code', 'R1')
            ->assertJsonPath('data.0.reason', 'Recent tattoo/piercing')
            // Haemoglobin is R2, in two (project owner, 2026-10-24).
            ->assertJsonPath('data.1.code', 'R2-High')
            ->assertJsonPath('data.1.reason', 'High haemoglobin')
            ->assertJsonPath('data.2.code', 'R2-Low')
            ->assertJsonPath('data.2.reason', 'Low haemoglobin')
            ->assertJsonPath('can.manage', true)
            ->assertJsonPath('can.view_reasons', true);

        // R2 before R10: the codes sort as people count.
        $this->assertSame('R10', $response->json('data.10.code'));

        $this->actingAs($this->physician)->getJson('/api/blood-center/deferral-codes')->assertOk();

        $this->assertSame(count(DeferralCode::DEFAULTS), DeferralCode::where('facility_id', $this->facility->id)->count());
    }

    public function test_whoever_types_a_paper_record_picks_by_code_and_never_reads_the_reason(): void
    {
        $this->actingAs($this->receptionist)
            ->getJson('/api/blood-center/deferral-codes')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'R1')
            ->assertJsonPath('data.0.reason', null)
            ->assertJsonMissingPath('data.0.uses')
            ->assertJsonPath('can.view_reasons', false)
            ->assertJsonPath('can.manage', false);

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/deferral-codes', ['code' => 'R13', 'reason' => 'Anything'])
            ->assertForbidden();
    }

    public function test_staff_who_record_no_deferral_cannot_list_the_codes(): void
    {
        $billing = User::factory()->bloodCenterStaff($this->facility, StaffRole::BillingClerk)->create();
        $admin = User::factory()->bloodCenterAdmin($this->facility)->create();

        $this->actingAs($billing)->getJson('/api/blood-center/deferral-codes')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/blood-center/deferral-codes')->assertForbidden();
    }

    // --- Keeping it ------------------------------------------------------------

    public function test_the_physician_adds_a_code_as_written_and_never_twice_in_any_case(): void
    {
        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferral-codes', ['code' => ' R13-Low ', 'reason' => '  Recent   blood transfusion '])
            ->assertCreated()
            ->assertJsonPath('data.code', 'R13-Low')
            ->assertJsonPath('data.reason', 'Recent blood transfusion');

        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferral-codes', ['code' => 'r13-low', 'reason' => 'Again'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferral-codes', ['code' => 'R 14!', 'reason' => 'Bad code'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        // Another centre's R13 is its own.
        $elsewhere = User::factory()->bloodCenterStaff(Facility::factory()->approved()->create(), StaffRole::ScreeningPhysician)->create();

        $this->actingAs($elsewhere)
            ->postJson('/api/blood-center/deferral-codes', ['code' => 'R13-Low', 'reason' => 'Something else'])
            ->assertCreated();
    }

    public function test_a_retired_code_is_not_offered_and_cannot_be_recorded(): void
    {
        $this->actingAs($this->physician)->getJson('/api/blood-center/deferral-codes');
        $r3 = DeferralCode::where('facility_id', $this->facility->id)->where('code', 'R3')->sole();

        $this->actingAs($this->physician)
            ->patchJson("/api/blood-center/deferral-codes/{$r3->id}", ['retired' => true])
            ->assertOk()
            ->assertJsonPath('data.retired', true);

        $codes = collect($this->actingAs($this->physician)->getJson('/api/blood-center/deferral-codes')->json('data'))->pluck('code');
        $this->assertNotContains('R3', $codes);

        $all = collect($this->actingAs($this->physician)->getJson('/api/blood-center/deferral-codes?include_retired=1')->json('data'))->pluck('code');
        $this->assertContains('R3', $all);

        $this->deferAtCounter('R3')->assertUnprocessable()->assertJsonValidationErrors('deferral_code');

        // Retired codes still hold their name.
        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferral-codes', ['code' => 'r3', 'reason' => 'Reused'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->actingAs($this->physician)
            ->patchJson("/api/blood-center/deferral-codes/{$r3->id}", ['retired' => false])
            ->assertOk()
            ->assertJsonPath('data.retired', false);

        $this->deferAtCounter('R3')->assertCreated();
    }

    public function test_rewording_a_code_leaves_past_records_as_they_were(): void
    {
        $id = $this->deferAtCounter('R2-Low')->assertCreated()->json('data.id');
        $low = DeferralCode::where('facility_id', $this->facility->id)->where('code', 'R2-Low')->sole();

        $this->actingAs($this->physician)
            ->patchJson("/api/blood-center/deferral-codes/{$low->id}", ['reason' => 'Haemoglobin below 12.5 g/dL'])
            ->assertOk()
            ->assertJsonPath('data.reason', 'Haemoglobin below 12.5 g/dL')
            ->assertJsonPath('data.uses', 1);

        $this->assertSame('Low haemoglobin', DonationScreening::where('donation_id', $id)->value('deferral_reason'));
    }

    public function test_a_code_on_a_record_keeps_its_name_but_an_unused_one_can_be_corrected(): void
    {
        $this->deferAtCounter('R1')->assertCreated();
        $codes = DeferralCode::where('facility_id', $this->facility->id)->get()->keyBy('code');

        $this->actingAs($this->physician)
            ->patchJson("/api/blood-center/deferral-codes/{$codes['R1']->id}", ['code' => 'R01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->actingAs($this->physician)
            ->patchJson("/api/blood-center/deferral-codes/{$codes['R3']->id}", ['code' => 'R03'])
            ->assertOk()
            ->assertJsonPath('data.code', 'R03');

        $this->actingAs($this->physician)
            ->patchJson("/api/blood-center/deferral-codes/{$codes['R4']->id}", ['code' => 'R5'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_another_centres_code_cannot_be_changed(): void
    {
        $theirs = DeferralCode::factory()->create();

        $this->actingAs($this->physician)
            ->patchJson("/api/blood-center/deferral-codes/{$theirs->id}", ['reason' => 'Mine now'])
            ->assertNotFound();
    }

    public function test_the_audit_trail_names_the_code_never_the_reason(): void
    {
        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferral-codes', ['code' => 'P1', 'reason' => 'Hepatitis B history'])
            ->assertCreated();

        $log = AuditLog::query()->where('action', 'center.deferral_code_added')->sole();

        $this->assertSame('P1', $log->context['code']);
        $this->assertStringNotContainsString('Hepatitis', json_encode($log->context));
    }

    // --- Other -----------------------------------------------------------------

    public function test_other_keeps_the_typed_reason_without_a_code_and_tells_the_donor_neither(): void
    {
        $response = $this->deferAtCounter(null, '  Awaiting cardiology review ')
            ->assertCreated()
            ->assertJsonPath('data.rejection_reason', 'Other')
            ->assertJsonPath('data.screening.deferral_code', null)
            ->assertJsonPath('data.screening.deferral_reason', 'Awaiting cardiology review');

        $donor = User::findOrFail(Donation::findOrFail($response->json('data.id'))->donor_id);

        Notification::assertSentTo($donor, DonorDeferred::class, function (DonorDeferred $notification) use ($donor): bool {
            $card = $notification->toDatabase($donor);
            $mail = $notification->toMail($donor)->render();

            return ! str_contains($card['desc'], 'cardiology') && ! str_contains($card['desc'], 'Deferral code')
                && ! str_contains($mail, 'cardiology') && ! str_contains($mail, 'Deferral code');
        });
    }

    public function test_a_deferral_takes_a_code_or_other_never_neither_nor_both(): void
    {
        $this->deferAtCounter(null)->assertUnprocessable()->assertJsonValidationErrors('deferral_code');
        $this->deferAtCounter('R1', 'Something typed')->assertUnprocessable()->assertJsonValidationErrors('other_reason');
    }

    public function test_the_deferrals_page_records_other_without_a_code(): void
    {
        $donor = User::factory()->donor()->create();
        Donation::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => $this->facility->id,
            'donation_date' => now()->subYear(),
            'status' => DonationStatus::Completed,
        ]);

        $this->actingAs($this->physician)
            ->postJson('/api/blood-center/deferrals', [
                'donor_uuid' => $donor->uuid,
                'outcome' => 'permanently_deferred',
                'other_reason' => 'Specialist advice on file',
            ])
            ->assertCreated()
            ->assertJsonPath('reason_code', null)
            ->assertJsonPath('reason', 'Specialist advice on file');
    }

    // --- Who reads what --------------------------------------------------------

    public function test_the_donations_list_shows_everyone_the_code_and_only_the_physician_the_reason(): void
    {
        $id = $this->deferAtCounter('R2-Low')->assertCreated()->json('data.id');

        $desk = collect($this->actingAs($this->receptionist)->getJson('/api/blood-center/donation-records')->json('data'))->firstWhere('id', $id);
        $this->assertSame('R2-Low', $desk['screening']['deferral_code']);
        $this->assertNull($desk['screening']['deferral_reason']);

        $clinical = collect($this->actingAs($this->physician)->getJson('/api/blood-center/donation-records')->json('data'))->firstWhere('id', $id);
        $this->assertSame('R2-Low', $clinical['screening']['deferral_code']);
        $this->assertSame('Low haemoglobin', $clinical['screening']['deferral_reason']);
    }

    public function test_the_collection_report_counts_by_code_and_names_the_reason_only_to_the_physician(): void
    {
        $this->deferAtCounter('R2-Low')->assertCreated();
        $this->deferAtCounter('R2-Low')->assertCreated();
        $this->deferAtCounter(null, 'Awaiting cardiology review')->assertCreated();

        $today = OperationalDay::todayAsDate();
        $url = "/api/blood-center/reports/collection?from={$today}&to={$today}";

        $desk = $this->actingAs($this->receptionist)->getJson($url)->assertOk()->json('deferrals.top_reasons');
        $this->assertSame(['code' => 'R2-Low', 'reason' => null, 'label' => 'R2-Low', 'count' => 2], $desk[0]);
        $this->assertSame(['code' => null, 'reason' => null, 'label' => 'Other', 'count' => 1], $desk[1]);

        $clinical = $this->actingAs($this->physician)->getJson($url)->assertOk()->json('deferrals.top_reasons');
        $this->assertSame('R2-Low · Low haemoglobin', $clinical[0]['label']);
        $this->assertSame('Low haemoglobin', $clinical[0]['reason']);
        $this->assertSame('Other · Awaiting cardiology review', $clinical[1]['label']);
    }
}
