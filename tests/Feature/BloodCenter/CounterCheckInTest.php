<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Models\DonationAppointment;
use App\Models\DonorProfile;
use App\Models\DonorQrToken;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifying who is standing at the counter, and registering them if they are new.
 */
class CounterCheckInTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->staff = User::factory()->bloodCenterStaff($this->facility, Department::Collection)->create();
    }

    /**
     * Issue a QR token the way EligibilityService does: store the digest, keep the raw value.
     *
     * @return array{0: User, 1: string}
     */
    private function issueQrToken(array $overrides = []): array
    {
        $donor = User::factory()->donor()->create();
        $raw = Str::random(40);

        DonorQrToken::factory()->create([
            'donor_id' => $donor->id,
            'token_hash' => hash('sha256', $raw),
            'issued_at' => now(),
            'expires_at' => now()->addDays(14),
            ...$overrides,
        ]);

        return [$donor, $raw];
    }

    public function test_a_valid_qr_code_identifies_the_donor(): void
    {
        [$donor, $raw] = $this->issueQrToken();

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $raw])
            ->assertOk()
            ->assertJsonPath('data.donor.uuid', $donor->uuid);
    }

    public function test_verifying_stamps_last_used_but_leaves_the_token_usable(): void
    {
        [$donor, $raw] = $this->issueQrToken();

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $raw])
            ->assertOk();

        $this->assertNotNull(DonorQrToken::where('donor_id', $donor->id)->value('last_used_at'));

        // A check-in interrupted halfway must not lock a donor out of their own
        // appointment, so the token is an identifier, not a single-use ticket.
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $raw])
            ->assertOk();
    }

    public function test_an_expired_token_is_refused(): void
    {
        [, $raw] = $this->issueQrToken(['expires_at' => now()->subDay()]);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $raw])
            ->assertNotFound()
            ->assertJsonPath('code', 'qr_invalid');
    }

    public function test_a_revoked_token_is_refused(): void
    {
        [, $raw] = $this->issueQrToken(['revoked_at' => now()]);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $raw])
            ->assertNotFound();
    }

    public function test_an_unknown_token_is_indistinguishable_from_an_expired_one(): void
    {
        [, $expiredRaw] = $this->issueQrToken(['expires_at' => now()->subDay()]);

        $unknown = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => Str::random(40)]);

        $expired = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $expiredRaw]);

        // Distinguishing them would let anyone holding a random string learn
        // whether it was ever a real token.
        $this->assertSame($expired->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($expired->json(), $unknown->json());
    }

    public function test_verifying_surfaces_the_donors_appointment_here(): void
    {
        [$donor, $raw] = $this->issueQrToken();

        $appointment = DonationAppointment::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => $this->facility->id,
            'appointment_datetime' => now(),
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $raw])
            ->assertOk()
            ->assertJsonPath('data.appointment.id', $appointment->id);
    }

    public function test_an_appointment_at_another_facility_is_not_surfaced(): void
    {
        [$donor, $raw] = $this->issueQrToken();

        DonationAppointment::factory()->create([
            'donor_id' => $donor->id,
            'facility_id' => Facility::factory()->approved()->create()->id,
            'appointment_datetime' => now(),
        ]);

        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/collection/verify-qr', ['token' => $raw])
            ->assertOk()
            ->assertJsonPath('data.appointment', null);
    }

    public function test_an_expected_donor_can_be_checked_in_and_marked_no_show(): void
    {
        $appointment = DonationAppointment::factory()->create([
            'donor_id' => User::factory()->donor()->create()->id,
            'facility_id' => $this->facility->id,
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/appointments/{$appointment->id}/check-in")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/appointments/{$appointment->id}/no-show")
            ->assertOk()
            ->assertJsonPath('data.status', 'no_show');
    }

    public function test_checking_in_twice_is_refused(): void
    {
        $appointment = DonationAppointment::factory()->create([
            'donor_id' => User::factory()->donor()->create()->id,
            'facility_id' => $this->facility->id,
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->staff)->postJson("/api/blood-center/appointments/{$appointment->id}/check-in")->assertOk();

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/appointments/{$appointment->id}/check-in")
            ->assertStatus(409)
            ->assertJsonPath('code', 'appointment_not_pending');
    }

    public function test_another_facilitys_appointment_is_not_found(): void
    {
        $foreign = DonationAppointment::factory()->create([
            'donor_id' => User::factory()->donor()->create()->id,
            'facility_id' => Facility::factory()->approved()->create()->id,
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/blood-center/appointments/{$foreign->id}/check-in")
            ->assertNotFound();

        $this->assertSame('scheduled', $foreign->fresh()->status);
    }

    public function test_a_walk_in_is_registered_with_a_valid_id_and_no_email(): void
    {
        $response = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/donors', [
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'valid_id_number' => 'PH-DL-12345',
                'birth_date' => now()->subYears(30)->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'own_facility');

        $donor = User::where('uuid', $response->json('data.uuid'))->firstOrFail();

        // The decision that made users.email nullable: a counter-registered
        // donor is identified by the ID they presented, not by an inbox.
        $this->assertNull($donor->email);
        $this->assertSame('PH-DL-12345', DonorProfile::where('donor_id', $donor->id)->value('valid_id_number'));
        Notification::assertNothingSent();
    }

    public function test_a_valid_id_is_required(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/donors', [
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'birth_date' => now()->subYears(30)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('valid_id_number');
    }

    public function test_a_duplicate_valid_id_is_refused(): void
    {
        $payload = [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'valid_id_number' => 'PH-DL-12345',
            'birth_date' => now()->subYears(30)->toDateString(),
        ];

        $this->actingAs($this->staff)->postJson('/api/blood-center/donors', $payload)->assertCreated();

        // The guard against forking one person's donation history across two
        // records, which would break the 56-day interval check.
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/donors', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('valid_id_number');
    }

    public function test_a_walk_in_who_supplies_an_email_is_sent_a_verification_link(): void
    {
        $response = $this->actingAs($this->staff)
            ->postJson('/api/blood-center/donors', [
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'valid_id_number' => 'PH-DL-99999',
                'email' => 'ana.reyes@example.com',
                'birth_date' => now()->subYears(25)->toDateString(),
            ])
            ->assertCreated();

        $donor = User::where('uuid', $response->json('data.uuid'))->firstOrFail();

        Notification::assertSentTo($donor, VerifyEmailNotification::class);
    }

    public function test_an_underage_donor_is_refused(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/blood-center/donors', [
                'first_name' => 'Too',
                'last_name' => 'Young',
                'valid_id_number' => 'PH-DL-00001',
                'birth_date' => now()->subYears(16)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('birth_date');
    }

    public function test_only_donors_manage_may_register_a_walk_in(): void
    {
        // Laboratory holds no donor abilities at all.
        $lab = User::factory()->bloodCenterStaff($this->facility, Department::Laboratory)->create();

        $this->actingAs($lab)
            ->postJson('/api/blood-center/donors', [
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'valid_id_number' => 'PH-DL-55555',
                'birth_date' => now()->subYears(30)->toDateString(),
            ])
            ->assertForbidden();
    }
}
