<?php

namespace Tests\Feature\Admin;

use App\Enums\IdentityStatus;
use App\Enums\RoleName;
use App\Enums\ValidIdType;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\FacilityDonor;
use App\Models\User;
use App\Notifications\DonorIdentityDecision;
use App\Support\AccountIdentity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DonorIdentityVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::factory()->withRole(RoleName::Admin)->create();
        $this->donor = User::factory()->donor()->create();
    }

    /**
     * Submit an ID as the donor and return the version an administrator would
     * then be reviewing.
     */
    private function submit(?User $donor = null, string $number = 'N0123456789'): int
    {
        $donor ??= $this->donor;

        $this->actingAs($donor)
            ->postJson('/api/donors/identity', [
                'valid_id_type' => ValidIdType::DriversLicense->value,
                'valid_id_number' => $number,
                'valid_id_image' => UploadedFile::fake()->create('id.jpg', 240, 'image/jpeg'),
            ])
            ->assertOk();

        return (int) $donor->donorProfile->fresh()->identity_submission_version;
    }

    public function test_an_administrator_sees_the_pending_queue(): void
    {
        $this->submit();

        $this->actingAs($this->admin)
            ->getJson('/api/admin/donor-identities')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $this->donor->uuid)
            ->assertJsonPath('data.0.identity_status', IdentityStatus::Pending->value);
    }

    public function test_the_queue_masks_the_id_number(): void
    {
        $this->submit();

        $body = $this->actingAs($this->admin)
            ->getJson('/api/admin/donor-identities')
            ->assertOk()
            ->assertJsonPath('data.0.valid_id_number_masked', '•••••••6789')
            ->getContent();

        // The administrator reads the number off the document image. A queue
        // carrying it in full would make an export of the queue an export of
        // every donor's ID number.
        $this->assertStringNotContainsString('N0123456789', $body);
    }

    public function test_an_administrator_verifies_an_id(): void
    {
        Notification::fake();

        $version = $this->submit();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/donor-identities/{$this->donor->uuid}/approve", [
                'submission_version' => $version,
            ])
            ->assertOk()
            ->assertJsonPath('donor.identity_status', IdentityStatus::Verified->value);

        $profile = $this->donor->donorProfile->fresh();

        $this->assertSame(IdentityStatus::Verified, $profile->identity_status);
        $this->assertSame($this->admin->id, $profile->identity_reviewed_by);
        $this->assertNotNull($profile->identity_reviewed_at);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'donor.identity_verified',
        ]);

        Notification::assertSentTo($this->donor, DonorIdentityDecision::class);
    }

    public function test_a_verified_donor_receives_an_in_app_notification(): void
    {
        $version = $this->submit();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/donor-identities/{$this->donor->uuid}/approve", [
                'submission_version' => $version,
            ])
            ->assertOk();

        $this->actingAs($this->donor)
            ->getJson('/api/donors/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.category', 'system')
            ->assertJsonPath('notifications.0.title', 'ID verified');
    }

    public function test_a_rejection_requires_a_reason(): void
    {
        $version = $this->submit();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/donor-identities/{$this->donor->uuid}/reject", [
                'submission_version' => $version,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_an_approval_refuses_a_reason(): void
    {
        $version = $this->submit();

        // Both endpoints share one request class, so the reason is required on
        // one route and prohibited on the other rather than loosely optional on
        // both. A reason posted here is a client bug worth surfacing.
        $this->actingAs($this->admin)
            ->postJson("/api/admin/donor-identities/{$this->donor->uuid}/approve", [
                'submission_version' => $version,
                'reason' => 'Looks fine',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_rejection_records_the_reason_and_lets_the_donor_resubmit(): void
    {
        $version = $this->submit();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/donor-identities/{$this->donor->uuid}/reject", [
                'submission_version' => $version,
                'reason' => 'The photo is too blurry to read.',
            ])
            ->assertOk()
            ->assertJsonPath('donor.identity_status', IdentityStatus::Rejected->value);

        $this->assertSame(
            'The photo is too blurry to read.',
            $this->donor->donorProfile->fresh()->identity_rejection_reason
        );

        // Rejected is not final: the donor sends a clearer photo.
        $this->assertSame(2, $this->submit());
    }

    public function test_a_decision_on_a_replaced_submission_is_refused(): void
    {
        // Frozen so both submissions land inside the same second. This is
        // exactly the case identity_submitted_at cannot tell apart, and why the
        // review token is a version rather than a timestamp.
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));

        $staleVersion = $this->submit();
        $currentVersion = $this->submit();

        $this->assertSame(
            $this->donor->donorProfile->fresh()->identity_submitted_at->toDateTimeString(),
            Carbon::getTestNow()->toDateTimeString(),
        );
        $this->assertNotSame($staleVersion, $currentVersion);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/donor-identities/{$this->donor->uuid}/approve", [
                'submission_version' => $staleVersion,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'identity_submission_stale');

        // Untouched: nobody has reviewed the document that is actually on file.
        $this->assertSame(IdentityStatus::Pending, $this->donor->donorProfile->fresh()->identity_status);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/donor-identities/{$this->donor->uuid}/approve", [
                'submission_version' => $currentVersion,
            ])
            ->assertOk();

        Carbon::setTestNow();
    }

    public function test_a_decision_on_an_id_that_is_not_pending_is_refused(): void
    {
        $version = $this->submit();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/donor-identities/{$this->donor->uuid}/approve", ['submission_version' => $version])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/donor-identities/{$this->donor->uuid}/approve", ['submission_version' => $version])
            ->assertStatus(409)
            ->assertJsonPath('code', 'identity_not_pending');
    }

    public function test_a_donor_cannot_reach_the_review_queue(): void
    {
        $version = $this->submit();

        $this->actingAs($this->donor)->getJson('/api/admin/donor-identities')->assertForbidden();

        $this->actingAs($this->donor)
            ->postJson("/api/admin/donor-identities/{$this->donor->uuid}/approve", [
                'submission_version' => $version,
            ])
            ->assertForbidden();
    }

    public function test_blood_center_staff_cannot_reach_the_review_queue(): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();

        $this->actingAs($staff)->getJson('/api/admin/donor-identities')->assertForbidden();
    }

    public function test_the_reject_route_is_named_so_the_request_can_branch_on_it(): void
    {
        // DonorIdentityDecisionRequest reads routeIs() to decide whether a reason
        // is required or prohibited, and routeIs() is false for an unnamed route.
        $this->assertSame(
            url("/api/admin/donor-identities/{$this->donor->uuid}/reject"),
            route('admin.donor-identities.reject', ['uuid' => $this->donor->uuid])
        );
    }

    /**
     * A donor account with this name and date of birth.
     */
    private function donorNamed(string $first, string $last, ?string $born): User
    {
        $donor = User::factory()->donor()->create(['first_name' => $first, 'last_name' => $last]);
        $donor->donorProfile->update(['birth_date' => $born]);

        return $donor;
    }

    /**
     * The possible matches the queue shows for the first pending submission.
     *
     * @return array<int, array<string, mixed>>
     */
    private function matchesInQueue(): array
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/admin/donor-identities')
            ->assertOk()
            ->json('data.0.possible_matches');
    }

    public function test_the_queue_shows_an_account_the_counter_already_registered_for_the_same_person(): void
    {
        $facility = Facility::factory()->approved()->create(['name' => 'Davao Blood Centre']);
        $existing = $this->donorNamed('Ana', 'Cruz', '1990-04-12');
        $existing->update(['email' => null]);
        FacilityDonor::create(['facility_id' => $facility->id, 'donor_id' => $existing->id, 'registered_here' => true]);

        $this->submit($this->donorNamed('Ana', 'Cruz', '1990-04-12'));

        $matches = $this->matchesInQueue();

        $this->assertCount(1, $matches);
        $this->assertSame($existing->uuid, $matches[0]['uuid']);
        $this->assertSame('name_and_birth_date', $matches[0]['likeness']);
        $this->assertSame('Davao Blood Centre', $matches[0]['registered_at']);
        $this->assertSame(AccountIdentity::donorCode($existing->id), $matches[0]['donor_code']);
    }

    public function test_names_match_regardless_of_case_and_surrounding_spaces(): void
    {
        $existing = $this->donorNamed(' ANA', 'cruz ', '1990-04-12');

        $this->submit($this->donorNamed('Ana', 'Cruz', '1990-04-12'));

        $this->assertSame([$existing->uuid], array_column($this->matchesInQueue(), 'uuid'));
    }

    public function test_a_birthday_with_one_of_the_names_is_a_match_and_ranks_below_the_full_name(): void
    {
        $maidenName = $this->donorNamed('Ana', 'Santos', '1990-04-12');
        $misspelt = $this->donorNamed('Anna', 'Cruz', '1990-04-12');
        $sameName = $this->donorNamed('Ana', 'Cruz', '1990-04-12');

        $this->submit($this->donorNamed('Ana', 'Cruz', '1990-04-12'));

        $matches = $this->matchesInQueue();

        $this->assertSame([$sameName->uuid, $misspelt->uuid, $maidenName->uuid], array_column($matches, 'uuid'));
        $this->assertSame(['name_and_birth_date', 'last_name_and_birth_date', 'first_name_and_birth_date'], array_column($matches, 'likeness'));
    }

    public function test_a_name_surname_or_birthday_alone_is_not_a_match(): void
    {
        // A namesake born on another day: a common name would otherwise list
        // every one of them in the network.
        $this->donorNamed('Ana', 'Cruz', '1985-01-30');
        $this->donorNamed('Jose', 'Cruz', '1979-08-01');
        $this->donorNamed('Maria', 'Reyes', '1990-04-12');

        // Staff are not donor accounts, whatever their name.
        User::factory()->bloodCenterStaff()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);

        $this->submit($this->donorNamed('Ana', 'Cruz', '1990-04-12'));

        $this->assertSame([], $this->matchesInQueue());
    }

    public function test_two_submissions_from_the_same_person_show_each_other_but_not_themselves(): void
    {
        $first = $this->donorNamed('Ana', 'Cruz', '1990-04-12');
        $second = $this->donorNamed('Ana', 'Cruz', '1990-04-12');

        $this->submit($first, 'N0123456789');
        $this->submit($second, 'N9876543210');

        $rows = collect($this->actingAs($this->admin)->getJson('/api/admin/donor-identities')->assertOk()->json('data'))
            ->keyBy('uuid');

        $this->assertSame([$second->uuid], array_column($rows[$first->uuid]['possible_matches'], 'uuid'));
        $this->assertSame([$first->uuid], array_column($rows[$second->uuid]['possible_matches'], 'uuid'));
    }

    public function test_a_possible_match_carries_its_id_number_masked(): void
    {
        $existing = $this->donorNamed('Ana', 'Cruz', '1990-04-12');
        $existing->donorProfile->update(['valid_id_number' => 'P1234567A']);

        $this->submit($this->donorNamed('Ana', 'Cruz', '1990-04-12'));

        $response = $this->actingAs($this->admin)->getJson('/api/admin/donor-identities')->assertOk();

        $response->assertJsonPath('data.0.possible_matches.0.valid_id_number_masked', '•••••567A');
        $this->assertStringNotContainsString('P1234567A', $response->getContent());
    }

    public function test_the_audit_trail_does_not_record_the_id_number(): void
    {
        $version = $this->submit();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/donor-identities/{$this->donor->uuid}/approve", ['submission_version' => $version])
            ->assertOk();

        $this->assertStringNotContainsString(
            'N0123456789',
            AuditLog::where('action', 'donor.identity_verified')->firstOrFail()->toJson()
        );
    }
}
