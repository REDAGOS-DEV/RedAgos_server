<?php

namespace Tests\Feature\Donor;

use App\Enums\EligibilityStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\EligibilityScreening;
use App\Models\EligibilityScreeningAnswer;
use App\Models\User;
use Database\Seeders\EligibilityQuestionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EligibilityScreeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EligibilityQuestionSeeder::class);
        $this->donor = User::factory()->donor()->create();
        $this->donor->donorProfile->update(['birth_date' => now()->subYears(30)->toDateString()]);
    }

    /**
     * Build a full set of passing answers, overriding individual codes as needed.
     *
     * @param  array<string, bool>  $overrides
     * @return array<int, array{code: string, answer: bool}>
     */
    private function answers(array $overrides = []): array
    {
        $passing = [
            'gh_1' => true,
            'gh_2' => false,
            'gh_3' => false,
            'gh_4' => false,
            'mh_1' => false,
            'mh_2' => false,
            'mh_3' => false,
            'mh_4' => true,
        ];

        return collect(array_merge($passing, $overrides))
            ->map(fn (bool $answer, string $code): array => ['code' => $code, 'answer' => $answer])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'question_version' => 1,
            'answers' => $this->answers(),
            'vitals' => ['weight' => 65],
        ], $overrides);
    }

    public function test_the_questionnaire_is_served_without_disqualification_flags(): void
    {
        $response = $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/questions')
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonCount(2, 'sections');

        $body = $response->getContent();

        $this->assertStringNotContainsString('disqualify_if_answer', $body);
        $this->assertStringContainsString('gh_1', $body);
    }

    public function test_a_passing_submission_is_eligible(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated()
            ->assertJsonPath('result', 'eligible')
            ->assertJsonPath('deferral_reasons', [])
            ->assertJsonPath('is_preliminary', true);
    }

    public function test_the_screening_is_valid_for_ninety_days(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated()
            ->assertJsonPath('screening_valid_until', now()->addDays(90)->toDateString());
    }

    public function test_a_forged_eligible_verdict_is_overridden_by_the_server(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers(['mh_1' => true]),
                'result' => 'eligible',
            ]))
            ->assertCreated()
            ->assertJsonPath('result', 'deferred');

        $screening = EligibilityScreening::latest('id')->first();

        $this->assertSame('eligible', $screening->submitted_result);
        $this->assertSame('deferred', $screening->computed_result);
    }

    public function test_a_client_server_divergence_is_audit_logged(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers(['mh_1' => true]),
                'result' => 'eligible',
            ]))
            ->assertCreated();

        $log = AuditLog::where('action', 'eligibility.screening.created')->firstOrFail();

        $this->assertFalse($log->context['result_matched_submission']);
        $this->assertSame($this->donor->id, $log->actor_id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function disqualifyingAnswers(): array
    {
        return [
            'not feeling well' => ['gh_1'],
            'recent fever or flu' => ['gh_2'],
            'donated in the last 90 days' => ['gh_4'],
            'infectious disease diagnosis' => ['mh_1'],
            'recent surgery or transfusion' => ['mh_2'],
            'under the minimum weight' => ['mh_4'],
        ];
    }

    #[DataProvider('disqualifyingAnswers')]
    public function test_each_disqualifying_answer_defers_on_its_own(string $code): void
    {
        $flipped = in_array($code, ['gh_1', 'mh_4'], true) ? false : true;

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers([$code => $flipped]),
            ]))
            ->assertCreated()
            ->assertJsonPath('result', 'deferred');
    }

    public function test_informational_answers_do_not_defer(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers(['gh_3' => true, 'mh_3' => true]),
            ]))
            ->assertCreated()
            ->assertJsonPath('result', 'eligible');
    }

    public function test_a_weight_below_fifty_kilograms_defers(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'vitals' => ['weight' => 49],
            ]))
            ->assertCreated()
            ->assertJsonPath('result', 'deferred')
            ->assertJsonPath('deferral_reasons.0.code', 'below_min_weight');
    }

    public function test_a_weight_of_exactly_fifty_kilograms_is_eligible(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'vitals' => ['weight' => 50],
            ]))
            ->assertCreated()
            ->assertJsonPath('result', 'eligible');
    }

    public function test_a_donation_fifty_five_days_ago_defers_on_the_interval(): void
    {
        Donation::factory()->completedAt(now()->subDays(55)->toDateString())->create([
            'donor_id' => $this->donor->id,
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated()
            ->assertJsonPath('result', 'deferred')
            ->assertJsonPath('deferral_reasons.0.code', 'below_min_interval');
    }

    public function test_a_donation_fifty_seven_days_ago_is_eligible(): void
    {
        Donation::factory()->completedAt(now()->subDays(57)->toDateString())->create([
            'donor_id' => $this->donor->id,
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated()
            ->assertJsonPath('result', 'eligible');
    }

    public function test_the_interval_ignores_donations_that_were_not_completed(): void
    {
        Donation::factory()->rejected()->create([
            'donor_id' => $this->donor->id,
            'donation_date' => now()->subDay(),
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated()
            ->assertJsonPath('result', 'eligible');
    }

    public function test_a_self_declared_last_donation_date_cannot_bypass_the_interval(): void
    {
        Donation::factory()->completedAt(now()->subDays(10)->toDateString())->create([
            'donor_id' => $this->donor->id,
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'vitals' => ['weight' => 65, 'last_donation_date' => now()->subYears(5)->toDateString()],
            ]))
            ->assertCreated()
            ->assertJsonPath('result', 'deferred')
            ->assertJsonPath('deferral_reasons.0.code', 'below_min_interval');
    }

    public function test_a_donor_under_eighteen_is_deferred_on_their_stored_birth_date(): void
    {
        $this->donor->donorProfile->update(['birth_date' => now()->subYears(16)->toDateString()]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated()
            ->assertJsonPath('result', 'deferred')
            ->assertJsonPath('deferral_reasons.0.code', 'below_min_age');
    }

    public function test_deferral_reasons_are_returned_with_readable_messages(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'vitals' => ['weight' => 45],
            ]))
            ->assertCreated()
            ->assertJsonPath('deferral_reasons.0.message', 'Donors must weigh at least 50 kilograms.');
    }

    public function test_multiple_failures_return_multiple_reasons(): void
    {
        $this->donor->donorProfile->update(['birth_date' => now()->subYears(15)->toDateString()]);

        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers(['mh_1' => true]),
                'vitals' => ['weight' => 40],
            ]))
            ->assertCreated();

        $codes = array_column($response->json('deferral_reasons'), 'code');

        $this->assertEqualsCanonicalizing(
            ['below_min_age', 'below_min_weight', 'questionnaire_response'],
            $codes
        );
    }

    public function test_an_incomplete_answer_set_is_rejected(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => [['code' => 'gh_1', 'answer' => true]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers');
    }

    public function test_a_stale_questionnaire_version_is_rejected(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'question_version' => 99,
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'questionnaire_version_stale')
            ->assertJsonPath('current_version', 1);
    }

    public function test_re_screening_while_a_valid_screening_stands_is_rejected(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'screening_already_valid');
    }

    public function test_re_screening_can_be_forced(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated();

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening?force=1', $this->payload())
            ->assertCreated();

        $this->assertSame(2, EligibilityScreening::count());
    }

    public function test_a_deferred_screening_does_not_block_re_screening(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'vitals' => ['weight' => 40],
            ]))
            ->assertCreated();

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertCreated()
            ->assertJsonPath('result', 'eligible');
    }

    public function test_answers_are_encrypted_at_rest(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers(['mh_1' => true]),
            ]))
            ->assertCreated();

        $raw = DB::table('eligibility_screening_answers')
            ->where('question_code', 'mh_1')
            ->value('answer');

        $this->assertNotSame('1', $raw);
        $this->assertNotSame('true', $raw);
        $this->assertGreaterThan(20, strlen($raw));

        $this->assertTrue(
            EligibilityScreeningAnswer::where('question_code', 'mh_1')->firstOrFail()->answer
        );
    }

    public function test_answers_never_appear_in_a_screening_response(): void
    {
        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->payload([
                'answers' => $this->answers(['mh_1' => true]),
            ]))
            ->assertCreated();

        $this->assertStringNotContainsString('mh_1', $response->getContent());
        $this->assertArrayNotHasKey('answers', $response->json());
    }

    public function test_prefill_returns_server_derived_values_only(): void
    {
        Donation::factory()->completedAt('2026-01-15')->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility/prefill')
            ->assertOk()
            ->assertJsonPath('age', 30)
            ->assertJsonPath('last_donation_date', '2026-01-15');
    }

    public function test_the_status_endpoint_reports_pending_before_any_screening(): void
    {
        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility')
            ->assertOk()
            ->assertJsonPath('eligibility_status', 'pending');
    }

    public function test_the_status_endpoint_reports_expired_once_validity_lapses(): void
    {
        EligibilityScreening::factory()->expired()->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility')
            ->assertOk()
            ->assertJsonPath('eligibility_status', EligibilityStatus::Expired->value);
    }

    public function test_the_status_endpoint_reports_the_next_eligible_date(): void
    {
        Donation::factory()->completedAt(now()->subDays(10)->toDateString())->create([
            'donor_id' => $this->donor->id,
        ]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility')
            ->assertOk()
            ->assertJsonPath('next_eligible_date', now()->subDays(10)->addDays(56)->toDateString());
    }

    public function test_screening_submission_is_throttled(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->actingAs($this->donor)
                ->postJson('/api/donors/eligibility/screening?force=1', $this->payload());
        }

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening?force=1', $this->payload())
            ->assertStatus(429);
    }

    public function test_eligibility_endpoints_reject_a_non_donor(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)->getJson('/api/donors/eligibility')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/donors/eligibility/questions')->assertForbidden();
        $this->actingAs($admin)
            ->postJson('/api/donors/eligibility/screening', $this->payload())
            ->assertForbidden();
    }

    public function test_eligibility_endpoints_reject_unauthenticated_callers(): void
    {
        $this->getJson('/api/donors/eligibility')->assertUnauthorized();
        $this->getJson('/api/donors/eligibility/questions')->assertUnauthorized();
        $this->postJson('/api/donors/eligibility/screening', $this->payload())->assertUnauthorized();
    }
}
