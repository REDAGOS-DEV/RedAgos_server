<?php

namespace Tests\Feature\Donor;

use App\Models\BloodType;
use App\Models\Donation;
use App\Models\DonorQrToken;
use App\Models\EligibilityScreening;
use App\Models\EligibilityScreeningAnswer;
use App\Models\User;
use Database\Seeders\EligibilityQuestionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Health data must never reach a response that is not explicitly about it.
 * These tests assert the absence of leakage rather than the presence of data.
 */
class SensitiveDataExposureTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EligibilityQuestionSeeder::class);
        $this->donor = User::factory()->donor()->create();
        $this->donor->donorProfile->update(['birth_date' => now()->subYears(30)->toDateString()]);

        $screening = EligibilityScreening::factory()->create(['donor_id' => $this->donor->id]);
        EligibilityScreeningAnswer::create([
            'screening_id' => $screening->id,
            'question_code' => 'mh_1',
            'answer' => true,
            'created_at' => now(),
        ]);
        Donation::factory()->completedAt('2026-02-01')->create(['donor_id' => $this->donor->id]);
    }

    /**
     * Endpoints that must never echo questionnaire answers.
     *
     * @return array<string, array{string}>
     */
    public static function donorEndpoints(): array
    {
        return [
            'dashboard' => ['/api/donors/dashboard'],
            'profile' => ['/api/donors/profile'],
            'current user' => ['/api/user'],
            'donations' => ['/api/donors/donations'],
            'qr code' => ['/api/donors/qr-code'],
            'eligibility status' => ['/api/donors/eligibility'],
            'appointments' => ['/api/donors/appointments'],
        ];
    }

    #[DataProvider('donorEndpoints')]
    public function test_no_endpoint_leaks_questionnaire_answers(string $uri): void
    {
        $body = $this->actingAs($this->donor)->getJson($uri)->assertOk()->getContent();

        $this->assertStringNotContainsString('mh_1', $body);
        $this->assertStringNotContainsString('Hepatitis', $body);
        $this->assertStringNotContainsString('"answer"', $body);
        $this->assertStringNotContainsString('answers', $body);
    }

    #[DataProvider('donorEndpoints')]
    public function test_no_endpoint_leaks_credentials_or_internal_keys(string $uri): void
    {
        $body = $this->actingAs($this->donor)->getJson($uri)->assertOk()->getContent();

        $this->assertStringNotContainsString('token_hash', $body);
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('remember_token', $body);
    }

    public function test_the_current_user_endpoint_does_not_expose_the_weight_or_screening_vitals(): void
    {
        $body = $this->actingAs($this->donor)->getJson('/api/user')->assertOk()->getContent();

        $this->assertStringNotContainsString('weight_kg', $body);
        $this->assertStringNotContainsString('age_at_screening', $body);
    }

    public function test_a_screening_answer_model_hides_its_value_when_serialised(): void
    {
        $answer = EligibilityScreeningAnswer::firstOrFail();

        $this->assertArrayNotHasKey('answer', $answer->toArray());
        $this->assertStringNotContainsString('answer', $answer->toJson());
    }

    public function test_a_qr_token_model_hides_its_hash_when_serialised(): void
    {
        $screening = EligibilityScreening::where('donor_id', $this->donor->id)->firstOrFail();
        $token = DonorQrToken::factory()->create([
            'donor_id' => $this->donor->id,
            'screening_id' => $screening->id,
        ]);

        $this->assertArrayNotHasKey('token_hash', $token->toArray());
    }

    public function test_a_donor_cannot_read_another_donors_eligibility_state(): void
    {
        $other = User::factory()->donor()->create();
        EligibilityScreening::factory()->deferred()->create(['donor_id' => $other->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/eligibility')
            ->assertOk()
            ->assertJsonPath('eligibility_status', 'eligible');
    }

    public function test_the_registration_response_does_not_echo_the_password(): void
    {
        // Idempotent: DonorProfileFactory also creates random blood types from an
        // eight-element pool, so an explicit create collides whenever they match.
        BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);

        $body = $this->postJson('/api/donors/register', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'leak-check@example.com',
            'phone' => '09181234567',
            'blood_type' => 'O+',
            'gender' => 'male',
            'birth_date' => '1995-05-20',
            'address' => '123 Rizal Street',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms_accepted' => true,
        ])->assertCreated()->getContent();

        $this->assertStringNotContainsString('Password123', $body);
        $this->assertStringNotContainsString('password', $body);
    }
}
