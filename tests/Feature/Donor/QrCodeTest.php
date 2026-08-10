<?php

namespace Tests\Feature\Donor;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\DonorQrToken;
use App\Models\EligibilityScreening;
use App\Models\User;
use Database\Seeders\EligibilityQuestionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QrCodeTest extends TestCase
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
     * @return array<string, mixed>
     */
    private function passingPayload(): array
    {
        $passing = [
            'gh_1' => true, 'gh_2' => false, 'gh_3' => false, 'gh_4' => false,
            'mh_1' => false, 'mh_2' => false, 'mh_3' => false, 'mh_4' => true,
        ];

        return [
            'question_version' => 1,
            'answers' => collect($passing)
                ->map(fn (bool $answer, string $code): array => ['code' => $code, 'answer' => $answer])
                ->values()->all(),
            'vitals' => ['weight' => 65],
        ];
    }

    public function test_a_passing_screening_issues_a_qr_token(): void
    {
        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->passingPayload())
            ->assertCreated();

        $this->assertNotEmpty($response->json('qr_token'));
        $this->assertSame(
            now()->addDays(14)->toDateString(),
            $response->json('qr_valid_until')
        );
    }

    public function test_the_token_is_valid_for_fourteen_days_independent_of_the_screening(): void
    {
        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->passingPayload())
            ->assertCreated();

        $this->assertSame(now()->addDays(90)->toDateString(), $response->json('screening_valid_until'));
        $this->assertSame(now()->addDays(14)->toDateString(), $response->json('qr_valid_until'));
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $this->passingPayload())
            ->assertCreated();

        $plainToken = $response->json('qr_token');
        $storedHash = DB::table('donor_qr_tokens')->value('token_hash');

        $this->assertNotSame($plainToken, $storedHash);
        $this->assertSame(hash('sha256', $plainToken), $storedHash);
        $this->assertNull(DB::table('donor_qr_tokens')->where('token_hash', $plainToken)->first());
    }

    public function test_a_deferred_screening_issues_no_token(): void
    {
        $payload = $this->passingPayload();
        $payload['vitals']['weight'] = 40;

        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening', $payload)
            ->assertCreated()
            ->assertJsonPath('result', 'deferred');

        $this->assertNull($response->json('qr_token'));
        $this->assertSame(0, DonorQrToken::count());
    }

    public function test_an_unverified_donor_receives_no_token(): void
    {
        $donor = User::factory()->unverified()->donor()->create();
        $donor->donorProfile->update(['birth_date' => now()->subYears(30)->toDateString()]);

        $response = $this->actingAs($donor)
            ->postJson('/api/donors/eligibility/screening', $this->passingPayload())
            ->assertCreated()
            ->assertJsonPath('result', 'eligible');

        $this->assertNull($response->json('qr_token'));
        $this->assertSame(0, DonorQrToken::count());
    }

    public function test_an_unverified_donor_cannot_refresh_a_token(): void
    {
        $donor = User::factory()->unverified()->donor()->create();
        EligibilityScreening::factory()->create(['donor_id' => $donor->id]);

        $this->actingAs($donor)
            ->postJson('/api/donors/qr-code/refresh')
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');
    }

    public function test_a_verified_donor_with_a_valid_screening_can_refresh(): void
    {
        EligibilityScreening::factory()->create(['donor_id' => $this->donor->id]);

        $response = $this->actingAs($this->donor)
            ->postJson('/api/donors/qr-code/refresh')
            ->assertOk();

        $this->assertNotEmpty($response->json('qr_token'));
        $this->assertSame(now()->addDays(14)->toDateString(), $response->json('qr_valid_until'));
    }

    public function test_refresh_works_when_the_screening_still_stands_but_the_token_expired(): void
    {
        $screening = EligibilityScreening::factory()->create([
            'donor_id' => $this->donor->id,
            'screened_at' => now()->subDays(30),
            'valid_until' => now()->addDays(60),
        ]);
        DonorQrToken::factory()->create([
            'donor_id' => $this->donor->id,
            'screening_id' => $screening->id,
            'issued_at' => now()->subDays(30),
            'expires_at' => now()->subDays(16),
        ]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/qr-code/refresh')
            ->assertOk();
    }

    public function test_refresh_is_refused_when_the_screening_has_expired(): void
    {
        EligibilityScreening::factory()->expired()->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/qr-code/refresh')
            ->assertForbidden()
            ->assertJsonPath('code', 'screening_required');
    }

    public function test_refresh_is_refused_when_the_donor_was_deferred(): void
    {
        EligibilityScreening::factory()->deferred()->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->postJson('/api/donors/qr-code/refresh')
            ->assertForbidden()
            ->assertJsonPath('code', 'screening_required');
    }

    public function test_refresh_is_refused_before_any_screening(): void
    {
        $this->actingAs($this->donor)
            ->postJson('/api/donors/qr-code/refresh')
            ->assertForbidden()
            ->assertJsonPath('code', 'screening_required');
    }

    public function test_refreshing_revokes_the_previous_token(): void
    {
        EligibilityScreening::factory()->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)->postJson('/api/donors/qr-code/refresh')->assertOk();
        $this->actingAs($this->donor)->postJson('/api/donors/qr-code/refresh')->assertOk();

        $this->assertSame(2, DonorQrToken::count());
        $this->assertSame(1, DonorQrToken::usable()->count());
    }

    public function test_a_new_screening_revokes_outstanding_tokens(): void
    {
        EligibilityScreening::factory()->create(['donor_id' => $this->donor->id]);
        $this->actingAs($this->donor)->postJson('/api/donors/qr-code/refresh')->assertOk();

        $this->actingAs($this->donor)
            ->postJson('/api/donors/eligibility/screening?force=1', $this->passingPayload())
            ->assertCreated();

        $this->assertSame(1, DonorQrToken::usable()->count());
    }

    public function test_the_qr_endpoint_never_returns_the_raw_token(): void
    {
        EligibilityScreening::factory()->create(['donor_id' => $this->donor->id]);
        $issued = $this->actingAs($this->donor)->postJson('/api/donors/qr-code/refresh')->assertOk();
        $plainToken = $issued->json('qr_token');

        $response = $this->actingAs($this->donor)->getJson('/api/donors/qr-code')->assertOk();

        $this->assertStringNotContainsString($plainToken, $response->getContent());
        $this->assertStringNotContainsString('token_hash', $response->getContent());
        $this->assertNull($response->json('profile.qr_token'));
        $this->assertTrue($response->json('has_active_token'));
    }

    public function test_the_qr_endpoint_reports_the_donor_card_details(): void
    {
        EligibilityScreening::factory()->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/qr-code')
            ->assertOk()
            ->assertJsonPath('eligibility_status', 'eligible')
            ->assertJsonPath('qr_valid_days', 14)
            ->assertJsonPath('profile.donor_id', 'DONOR-'.str_pad((string) $this->donor->id, 6, '0', STR_PAD_LEFT))
            ->assertJsonStructure(['profile' => ['full_name', 'blood_type', 'screening_date', 'screening_valid_until']]);
    }

    public function test_the_qr_endpoint_reports_deferred_status_without_a_token(): void
    {
        EligibilityScreening::factory()->deferred()->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/qr-code')
            ->assertOk()
            ->assertJsonPath('eligibility_status', 'deferred')
            ->assertJsonPath('has_active_token', false);
    }

    public function test_issuing_a_token_is_audit_logged(): void
    {
        EligibilityScreening::factory()->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)->postJson('/api/donors/qr-code/refresh')->assertOk();

        $log = AuditLog::where('action', 'donor.qr_code.issued')->firstOrFail();

        $this->assertSame($this->donor->id, $log->actor_id);
        $this->assertArrayNotHasKey('qr_token', $log->context);
    }

    public function test_viewing_the_qr_code_is_audit_logged(): void
    {
        EligibilityScreening::factory()->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)->getJson('/api/donors/qr-code')->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'donor.qr_code.viewed')->count());
    }

    public function test_refresh_is_throttled(): void
    {
        EligibilityScreening::factory()->create(['donor_id' => $this->donor->id]);

        foreach (range(1, 10) as $attempt) {
            $this->actingAs($this->donor)->postJson('/api/donors/qr-code/refresh');
        }

        $this->actingAs($this->donor)
            ->postJson('/api/donors/qr-code/refresh')
            ->assertStatus(429);
    }

    public function test_qr_endpoints_reject_a_non_donor(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)->getJson('/api/donors/qr-code')->assertForbidden();
        $this->actingAs($admin)->postJson('/api/donors/qr-code/refresh')->assertForbidden();
    }

    public function test_qr_endpoints_reject_unauthenticated_callers(): void
    {
        $this->getJson('/api/donors/qr-code')->assertUnauthorized();
        $this->postJson('/api/donors/qr-code/refresh')->assertUnauthorized();
    }
}
