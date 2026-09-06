<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\FacilityStatus;
use App\Enums\RoleName;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The resubmission flow is gone, and the records it produced are not.
 *
 * This file used to cover an applicant correcting a rejected registration.
 * There is no applicant now — facilities are created by a Super Admin — so what
 * matters is the other half of that change: the blood centres already sitting
 * in the queue when the flow was removed must keep their rows, and the Super
 * Admin must be able to clear them by hand rather than by a data migration.
 */
class RegistrationResubmissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        return User::factory()->withRole(RoleName::Admin)->create();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function removedApplicantRoutes(): array
    {
        return [
            'registration status' => ['get', '/api/blood-center/registration-status'],
            'resubmit' => ['post', '/api/blood-center/registration/resubmit'],
        ];
    }

    #[DataProvider('removedApplicantRoutes')]
    public function test_the_applicant_routes_are_gone_even_for_the_applicant(string $method, string $uri): void
    {
        $facility = Facility::factory()->rejected()->create();
        $applicant = User::factory()->bloodCenterApplicant($facility)->create();

        $this->actingAs($applicant)->json($method, $uri)->assertNotFound();

        // The record is untouched by the attempt.
        $this->assertSame(FacilityStatus::Rejected, $facility->fresh()->status);
    }

    public function test_a_pending_registration_from_before_the_change_is_preserved(): void
    {
        $facility = Facility::factory()->pendingApproval()->create([
            'name' => 'Legacy Applicant Center',
            'doh_license_number' => 'DOH-BC-LEGACY-1',
        ]);
        $applicant = User::factory()->bloodCenterApplicant($facility)->create();

        // Nothing sweeps these away: the row, its applicant and the link
        // between them all survive.
        $this->assertDatabaseHas('facilities', [
            'id' => $facility->id,
            'doh_license_number' => 'DOH-BC-LEGACY-1',
            'status' => FacilityStatus::PendingApproval->value,
            'registration_contact_user_id' => $applicant->id,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $applicant->id,
            'facility_id' => $facility->id,
        ]);
    }

    public function test_a_legacy_pending_registration_is_still_listed_for_the_super_admin(): void
    {
        Facility::factory()->pendingApproval()->create(['name' => 'Legacy Applicant Center']);

        $names = array_column(
            $this->actingAs($this->admin())
                ->getJson('/api/admin/facilities?status=pending_approval')
                ->assertOk()
                ->json('data'),
            'name'
        );

        $this->assertContains('Legacy Applicant Center', $names);
    }

    public function test_the_super_admin_can_resolve_a_legacy_pending_registration_by_approving_it(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        $applicant = User::factory()->bloodCenterApplicant($facility)->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facilities/{$facility->id}/approve")
            ->assertOk()
            ->assertJsonPath('facility.status', FacilityStatus::Approved->value);

        $applicant = $applicant->fresh();

        $this->assertTrue($applicant->hasRole(RoleName::BloodCenter));
        $this->assertTrue($applicant->is_supervisor);
    }

    public function test_the_super_admin_can_resolve_a_legacy_pending_registration_by_rejecting_it(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        $applicant = User::factory()->bloodCenterApplicant($facility)->create();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facilities/{$facility->id}/reject", [
                'reason' => 'Superseded by an administrator-created record.',
            ])
            ->assertOk();

        // Rejected, not deleted: the record stays readable.
        $this->assertSame(FacilityStatus::Rejected, $facility->fresh()->status);
        $this->assertFalse($applicant->fresh()->hasRole(RoleName::BloodCenter));
    }

    public function test_a_legacy_applicant_is_told_why_it_cannot_sign_in(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        User::factory()->bloodCenterApplicant($facility)->create([
            'email' => 'legacy@example.ph',
        ]);

        // There is no status screen to send them to any more, so the refusal
        // has to carry the explanation itself.
        $this->postJson('/api/login', [
            'email' => 'legacy@example.ph',
            'password' => 'password',
            'role' => 'blood-center',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'facility_not_activated');
    }

    public function test_a_legacy_applicant_whose_facility_is_approved_can_sign_in(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        User::factory()->bloodCenterApplicant($facility)->create([
            'email' => 'legacy@example.ph',
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/facilities/{$facility->id}/approve")
            ->assertOk();

        $this->postJson('/api/login', [
            'email' => 'legacy@example.ph',
            'password' => 'password',
            'role' => 'blood-center',
        ])
            ->assertOk()
            ->assertJsonPath('user.facility.status', FacilityStatus::Approved->value);
    }
}
