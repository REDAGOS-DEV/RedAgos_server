<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\DonationStatus;
use App\Enums\ScreeningOutcome;
use App\Enums\StaffRole;
use App\Mail\DonorCenterEmail;
use App\Models\BloodCollection;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\DonationScreening;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\DonorContactRequested;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Testing works blind, its head included (audit, 2026-10-25).
 *
 * The Serology Technologist, a typed Testing role (a "Laboratory Assistant")
 * and the Laboratory Supervisor find a tube by its donation barcode and
 * record against the donation the server resolves it to within their own
 * centre. Nothing any of them is sent says who the donor is: no name,
 * contact details, donor code or uuid, and none of the donor's clinical
 * history. Even the counselling referrals go by barcode: Testing refers the
 * donor to a doctor, and the contact request reaches the donor from the
 * server.
 *
 * Each surface is checked for all three: the queue, the donation detail,
 * the serology results list, its CSV, and the barcode search.
 */
class TestingDonorBlindingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $donor;

    private Donation $donation;

    /** An earlier visit turned away at screening: the donor's clinical history. */
    private Donation $turnedAway;

    private User $technologist;

    private User $assistant;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();

        $this->facility = Facility::factory()->approved()->create();

        $this->donor = User::factory()->donor()->create([
            'first_name' => 'Rosalinda',
            'last_name' => 'Magbanua',
            'email' => 'rosalinda.magbanua@example.test',
            'phone' => '+639171234567',
        ]);

        $this->turnedAway = Donation::factory()->rejected()->create([
            'facility_id' => $this->facility->id,
            'donor_id' => $this->donor->id,
            'donation_date' => now()->subMonths(5),
            'rejection_reason' => 'R7-HAEM',
        ]);

        DonationScreening::factory()->create([
            'donation_id' => $this->turnedAway->id,
            'facility_id' => $this->facility->id,
            'outcome' => ScreeningOutcome::TemporarilyDeferred,
            'deferral_reason' => 'Haemoglobin below the minimum',
            'haemoglobin_g_dl' => 11.3,
        ]);

        $this->donation = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'donor_id' => $this->donor->id,
            'status' => DonationStatus::Collected,
        ]);

        BloodCollection::where('donation_id', $this->donation->id)->update(['donation_barcode' => 'SEG-7781']);

        $this->technologist = User::factory()->bloodCenterStaff($this->facility, StaffRole::SerologyTechnologist)->create();
        $this->assistant = User::factory()->bloodCenterCustomStaff($this->facility, Department::Testing, 'Laboratory Assistant')->create();
        $this->supervisor = User::factory()->bloodCenterStaff($this->facility, StaffRole::LabSupervisor)->create();
    }

    /**
     * Everyone in Testing, every one of them blind.
     *
     * @return array<string, User>
     */
    private function blindStaff(): array
    {
        return [
            'the serology technologist' => $this->technologist,
            'a typed Testing role' => $this->assistant,
            'the laboratory supervisor' => $this->supervisor,
        ];
    }

    private function donorCode(): string
    {
        return 'DONOR-'.str_pad((string) $this->donor->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Everything that says who the donor is, or what was found when they were screened.
     *
     * @return array<string, string>
     */
    private function identifying(): array
    {
        return [
            'first name' => 'Rosalinda',
            'last name' => 'Magbanua',
            'email' => 'rosalinda.magbanua',
            'phone' => '9171234567',
            'uuid' => $this->donor->uuid,
            'donor code' => $this->donorCode(),
            'deferral code' => 'R7-HAEM',
            'deferral reason' => 'Haemoglobin below',
        ];
    }

    private function assertBlind(string $content, string $who): void
    {
        foreach ($this->identifying() as $what => $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $content, "{$who} was sent the donor's {$what}.");
        }
    }

    /**
     * The donor block, blinded: the blood type on the bag and nothing more.
     *
     * @param  array<string, mixed>|null  $donor
     */
    private function assertBlindedBlock(?array $donor, string $who): void
    {
        $this->assertNotNull($donor, $who);
        $this->assertTrue($donor['blinded'], $who);
        $this->assertSame(['blinded', 'blood_type'], array_keys($donor), $who);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function recordSerology(User $as, array $overrides = []): TestResponse
    {
        return $this->actingAs($as)->postJson(
            "/api/blood-center/laboratory/donations/{$this->donation->id}/serology",
            [
                'hiv' => 'non_reactive',
                'hbsag' => 'non_reactive',
                'hcv' => 'non_reactive',
                'syphilis' => 'non_reactive',
                'malaria' => 'non_reactive',
                ...$overrides,
            ]
        )->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, int>
     */
    private function resultIds(User $viewer, array $query): array
    {
        return array_column(
            $this->actingAs($viewer)
                ->getJson('/api/blood-center/laboratory/serology-results?'.http_build_query($query))
                ->assertOk()
                ->json('data'),
            'donation_id'
        );
    }

    // --- Queue --------------------------------------------------------------

    public function test_the_queue_names_the_bag_never_the_donor(): void
    {
        foreach ($this->blindStaff() as $who => $viewer) {
            $response = $this->actingAs($viewer)
                ->getJson('/api/blood-center/laboratory/queue?stage=testing')
                ->assertOk()
                ->assertJsonPath('data.0.id', $this->donation->id)
                ->assertJsonPath('data.0.collection.donation_barcode', 'SEG-7781');

            $this->assertBlindedBlock($response->json('data.0.donor'), $who);
            $this->assertBlind($response->getContent(), $who);
        }
    }

    public function test_no_queue_filter_reaches_past_the_bags_into_the_counters_visits(): void
    {
        // The earlier visit was rejected at screening with a deferral code.
        // It left no bag, so it is not the laboratory's, under any filter.
        foreach ($this->blindStaff() as $who => $viewer) {
            foreach (['status=rejected', 'status=registered', 'status=screening', ''] as $filter) {
                $response = $this->actingAs($viewer)
                    ->getJson('/api/blood-center/laboratory/queue?'.$filter)
                    ->assertOk();

                $this->assertNotContains($this->turnedAway->id, array_column($response->json('data'), 'id'), "{$who}, {$filter}");
                $this->assertBlind($response->getContent(), "{$who}, {$filter}");
            }
        }
    }

    // --- Donation detail ----------------------------------------------------

    public function test_the_donation_detail_names_the_bag_never_the_donor(): void
    {
        foreach ($this->blindStaff() as $who => $viewer) {
            $response = $this->actingAs($viewer)
                ->getJson("/api/blood-center/laboratory/donations/{$this->donation->id}")
                ->assertOk()
                ->assertJsonPath('id', $this->donation->id)
                ->assertJsonPath('collection.donation_barcode', 'SEG-7781');

            $this->assertBlindedBlock($response->json('donor'), $who);
            $this->assertBlind($response->getContent(), $who);
        }
    }

    public function test_a_donation_id_does_not_reach_a_visit_that_left_no_bag(): void
    {
        foreach ($this->blindStaff() as $who => $viewer) {
            $response = $this->actingAs($viewer)
                ->getJson("/api/blood-center/laboratory/donations/{$this->turnedAway->id}")
                ->assertNotFound();

            $this->assertBlind($response->getContent(), $who);
        }
    }

    // --- Serology results -----------------------------------------------------

    public function test_the_serology_results_list_names_the_bag_never_the_donor(): void
    {
        $this->recordSerology($this->technologist);

        foreach ($this->blindStaff() as $who => $viewer) {
            $response = $this->actingAs($viewer)
                ->getJson('/api/blood-center/laboratory/serology-results')
                ->assertOk()
                ->assertJsonPath('data.0.donation_id', $this->donation->id)
                ->assertJsonPath('data.0.bag.barcode', 'SEG-7781');

            $this->assertBlindedBlock($response->json('data.0.donor'), $who);
            $this->assertBlind($response->getContent(), $who);
        }
    }

    public function test_a_reactive_result_is_verified_and_referred_by_barcode_alone(): void
    {
        // Saved by a technologist, a reactive panel waits for the supervisor.
        $saved = $this->recordSerology($this->technologist, ['hcv' => 'reactive', 'confirm_reactive' => true]);

        $this->assertBlindedBlock($saved->json('data.donor'), 'the serology technologist');
        $this->assertBlind($saved->getContent(), 'the serology technologist');

        // Supervisory review is by the bag too.
        $waiting = $this->actingAs($this->supervisor)
            ->getJson('/api/blood-center/laboratory/serology-results?outcome=awaiting_verification')
            ->assertOk()
            ->assertJsonPath('data.0.donation_id', $this->donation->id)
            ->assertJsonPath('data.0.bag.barcode', 'SEG-7781')
            ->assertJsonPath('data.0.can.verify', true);

        $this->assertBlind($waiting->getContent(), 'the laboratory supervisor');

        $verified = $this->actingAs($this->supervisor)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/serology/verify")
            ->assertOk();

        $this->assertBlind($verified->getContent(), 'the laboratory supervisor');

        // The counselling referral: by barcode, with whether the request goes by email.
        $referrals = $this->actingAs($this->supervisor)
            ->getJson('/api/blood-center/laboratory/referrals?status=all')
            ->assertOk()
            ->assertJsonPath('data.0.donation.donation_barcode', 'SEG-7781')
            ->assertJsonPath('data.0.reactive_markers.0.value', 'hcv')
            ->assertJsonPath('data.0.contact_by_email', true);

        $this->assertBlindedBlock($referrals->json('data.0.donor'), 'the laboratory supervisor');
        $this->assertBlind($referrals->getContent(), 'the laboratory supervisor');

        // The donor is still reached, by the server, without anyone in Testing learning who.
        $referral = CounsellingReferral::where('donation_id', $this->donation->id)->sole();

        $contacted = $this->actingAs($this->supervisor)
            ->postJson("/api/blood-center/laboratory/referrals/{$referral->id}/contact-request")
            ->assertOk()
            ->assertJsonPath('message', 'Contact request sent to the donor of bag SEG-7781 by email and in the portal.');

        $this->assertBlind($contacted->getContent(), 'the laboratory supervisor');
        Mail::assertQueued(DonorCenterEmail::class, fn (DonorCenterEmail $mail): bool => $mail->hasTo('rosalinda.magbanua@example.test'));
        Notification::assertSentTo($this->donor, DonorContactRequested::class);

        // Referred to a doctor (project owner, 2026-10-26), still by the bag.
        $referred = $this->actingAs($this->supervisor)
            ->patchJson("/api/blood-center/laboratory/referrals/{$referral->id}", ['status' => 'referred'])
            ->assertOk()
            ->assertJsonPath('data.status_label', 'Referred to doctor');

        $this->assertBlind($referred->getContent(), 'the laboratory supervisor');

        // The referral list stays the supervisor's.
        foreach ([$this->technologist, $this->assistant] as $viewer) {
            $this->actingAs($viewer)->getJson('/api/blood-center/laboratory/referrals?status=all')->assertForbidden();
            $this->actingAs($viewer)->getJson('/api/blood-center/laboratory/referrals/summary')->assertForbidden();
        }

        // The verified result, read back from every Testing surface, still names no one.
        foreach ($this->blindStaff() as $who => $viewer) {
            foreach ([
                "/api/blood-center/laboratory/donations/{$this->donation->id}",
                '/api/blood-center/laboratory/serology-results',
            ] as $url) {
                $this->assertBlind($this->actingAs($viewer)->getJson($url)->assertOk()->getContent(), "{$who} at {$url}");
            }
        }
    }

    // --- CSV export -----------------------------------------------------------

    public function test_the_csv_has_no_donor_columns_for_anyone_in_testing(): void
    {
        $this->recordSerology($this->technologist);

        foreach ($this->blindStaff() as $who => $viewer) {
            $csv = $this->actingAs($viewer)
                ->get('/api/blood-center/laboratory/serology-results/export')
                ->assertOk()
                ->streamedContent();

            $this->assertStringStartsWith("\xEF\xBB\xBFDonationID,Bag,BloodGroup,HIV,", $csv, $who);
            $this->assertStringContainsString('SEG-7781', $csv, $who);
            $this->assertBlind($csv, $who);
        }
    }

    // --- Barcode search -------------------------------------------------------

    public function test_testing_finds_and_records_a_tube_by_its_barcode_within_its_own_centre(): void
    {
        // The same sticker at another centre: a barcode resolves to a donation
        // at the caller's facility only.
        $elsewhere = Donation::factory()->create([
            'facility_id' => Facility::factory()->approved()->create()->id,
            'status' => DonationStatus::Collected,
        ]);

        BloodCollection::where('donation_id', $elsewhere->id)->update(['donation_barcode' => 'SEG-7781']);

        foreach ($this->blindStaff() as $who => $viewer) {
            $found = $this->actingAs($viewer)
                ->getJson('/api/blood-center/laboratory/queue?stage=testing&barcode='.urlencode(' seg-7781 '))
                ->assertOk();

            $this->assertSame([$this->donation->id], array_column($found->json('data'), 'id'), $who);
            $this->assertBlind($found->getContent(), $who);
        }

        // Scan, open what the scan resolved to, and record against it.
        $scanned = $this->actingAs($this->technologist)
            ->getJson('/api/blood-center/laboratory/queue?stage=testing&barcode=SEG-7781')
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($scanned['donor']['blinded']);

        $recorded = $this->actingAs($this->technologist)
            ->postJson("/api/blood-center/laboratory/donations/{$scanned['id']}/serology", [
                'hiv' => 'non_reactive',
                'hbsag' => 'non_reactive',
                'hcv' => 'non_reactive',
                'syphilis' => 'non_reactive',
                'malaria' => 'non_reactive',
            ])
            ->assertCreated()
            ->assertJsonPath('data.collection.donation_barcode', 'SEG-7781');

        $this->assertBlindedBlock($recorded->json('data.donor'), 'the serology technologist');
        $this->assertBlind($recorded->getContent(), 'the serology technologist');
    }

    public function test_the_results_search_finds_a_barcode_or_donation_and_never_a_donor(): void
    {
        $this->recordSerology($this->technologist);

        $byDonor = [
            'name' => 'Magbanua',
            'full name' => 'Rosalinda Magbanua',
            'donor code' => $this->donorCode(),
            'uuid' => $this->donor->uuid,
            'email' => 'rosalinda.magbanua@example.test',
        ];

        foreach ($this->blindStaff() as $who => $viewer) {
            $this->assertSame([$this->donation->id], $this->resultIds($viewer, ['search' => 'seg-7781']), $who);
            $this->assertSame([$this->donation->id], $this->resultIds($viewer, ['search' => 'DON-'.$this->donation->id]), $who);

            // A search that narrowed the list to one panel would say whose it is.
            foreach ($byDonor as $what => $term) {
                $this->assertSame([], $this->resultIds($viewer, ['search' => $term]), "{$who} found a panel by the donor's {$what}.");
            }
        }
    }

    // --- Who holds what -------------------------------------------------------

    public function test_nobody_in_testing_holds_a_donor_read_and_the_referrals_stay_the_supervisors(): void
    {
        foreach ($this->blindStaff() as $who => $viewer) {
            $abilities = $viewer->abilities();

            $this->assertContains('lab.record_serology', $abilities, $who);
            $this->assertContains('lab.record_immunohematology', $abilities, $who);
            $this->assertSame([], array_values(array_filter(
                $abilities,
                fn (string $ability): bool => str_starts_with($ability, 'donors.')
            )), $who);
        }

        $this->assertContains('lab.referrals', $this->supervisor->abilities());
        $this->assertContains('lab.verify_serology', $this->supervisor->abilities());
        $this->assertNotContains('lab.referrals', $this->assistant->abilities());
        $this->assertNotContains('lab.verify_serology', $this->assistant->abilities());
    }
}
