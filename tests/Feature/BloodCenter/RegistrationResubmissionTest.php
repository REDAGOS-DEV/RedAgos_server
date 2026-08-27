<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\FacilityStatus;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RegistrationResubmissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Facility $facility, array $overrides = []): array
    {
        return array_merge([
            'center_name' => $facility->name,
            // The same DOH licence the organisation actually holds — a real
            // applicant does not get a new one because it was turned down.
            'doh_license_number' => $facility->doh_license_number,
            'contact_person' => 'Maria Santos',
            'address' => 'Quirino Ave, Davao City',
            'description' => 'Corrected details.',
        ], $overrides);
    }

    public function test_the_registration_contact_can_resubmit_with_the_same_license(): void
    {
        $facility = Facility::factory()->rejected()->create();
        $applicant = User::factory()->bloodCenterApplicant($facility)->create();

        $this->actingAs($applicant)
            ->postJson('/api/blood-center/registration/resubmit', $this->payload($facility))
            ->assertOk()
            ->assertJsonPath('facility.status', FacilityStatus::PendingApproval->value);

        $facility->refresh();

        $this->assertSame(FacilityStatus::PendingApproval, $facility->status);
        $this->assertNull($facility->rejection_reason);
        $this->assertNotNull($facility->resubmitted_at);
    }

    public function test_a_different_user_at_the_same_facility_cannot_resubmit(): void
    {
        $facility = Facility::factory()->rejected()->create();
        User::factory()->bloodCenterApplicant($facility)->create();
        $colleague = User::factory()->create(['facility_id' => $facility->id]);

        $this->actingAs($colleague)
            ->postJson('/api/blood-center/registration/resubmit', $this->payload($facility))
            ->assertForbidden()
            ->assertJsonPath('code', 'not_registration_contact');

        $this->assertSame(FacilityStatus::Rejected, $facility->refresh()->status);
    }

    public function test_a_user_cannot_touch_another_facilitys_registration(): void
    {
        $victim = Facility::factory()->rejected()->create();
        User::factory()->bloodCenterApplicant($victim)->create();

        $outsiderFacility = Facility::factory()->rejected()->create();
        $outsider = User::factory()->bloodCenterApplicant($outsiderFacility)->create();

        // The endpoint resolves the facility from the token, never from input,
        // so there is no id for an outsider to tamper with — their resubmission
        // only ever moves their own facility.
        $this->actingAs($outsider)
            ->postJson('/api/blood-center/registration/resubmit', $this->payload($outsiderFacility))
            ->assertOk();

        $this->assertSame(FacilityStatus::Rejected, $victim->refresh()->status);
    }

    public function test_resubmitting_a_pending_registration_is_refused(): void
    {
        $facility = Facility::factory()->pendingApproval()->create();
        $applicant = User::factory()->bloodCenterApplicant($facility)->create();

        $this->actingAs($applicant)
            ->postJson('/api/blood-center/registration/resubmit', $this->payload($facility))
            ->assertStatus(409)
            ->assertJsonPath('code', 'facility_not_rejected');
    }

    public function test_resubmitting_an_approved_registration_is_refused(): void
    {
        $facility = Facility::factory()->approved()->create();
        $applicant = User::factory()->bloodCenterApplicant($facility)->create();

        $this->actingAs($applicant)
            ->postJson('/api/blood-center/registration/resubmit', $this->payload($facility))
            ->assertStatus(409)
            ->assertJsonPath('code', 'facility_not_rejected');
    }

    public function test_the_status_endpoint_reports_whether_resubmission_is_possible(): void
    {
        $facility = Facility::factory()->rejected()->create();
        $contact = User::factory()->bloodCenterApplicant($facility)->create();
        $colleague = User::factory()->create(['facility_id' => $facility->id]);

        $this->actingAs($contact)
            ->getJson('/api/blood-center/registration-status')
            ->assertOk()
            ->assertJsonPath('can_resubmit', true)
            ->assertJsonPath('facility.status', FacilityStatus::Rejected->value)
            ->assertJsonPath('facility.rejection_reason', 'The DOH licence could not be verified.');

        // The UI must not offer a button the API would refuse.
        $this->actingAs($colleague)
            ->getJson('/api/blood-center/registration-status')
            ->assertOk()
            ->assertJsonPath('can_resubmit', false);
    }

    public function test_a_fresh_registration_reusing_a_rejected_license_is_still_refused(): void
    {
        $facility = Facility::factory()->rejected()->create(['doh_license_number' => 'DOH-BC-2026-00412']);
        User::factory()->bloodCenterApplicant($facility)->create();

        // A stranger must not be able to claim a rejected facility by quoting
        // its licence number.
        $this->postJson('/api/blood-center/register', [
            'center_name' => 'Impostor Blood Center',
            'doh_license_number' => 'DOH-BC-2026-00412',
            'contact_first_name' => 'Not',
            'contact_last_name' => 'Maria',
            'position' => 'Administrator',
            'email' => 'impostor@example.ph',
            'phone' => '09189999999',
            'address' => 'Elsewhere',
            'password' => 'SecurePass1',
            'password_confirmation' => 'SecurePass1',
        ])->assertStatus(422)->assertJsonValidationErrors('doh_license_number');
    }

    public function test_resubmission_validates_its_fields(): void
    {
        $facility = Facility::factory()->rejected()->create();
        $applicant = User::factory()->bloodCenterApplicant($facility)->create();

        $this->actingAs($applicant)
            ->postJson('/api/blood-center/registration/resubmit', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['center_name', 'doh_license_number', 'contact_person', 'address']);
    }

    public function test_a_user_without_a_facility_gets_a_clear_error(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/blood-center/registration-status')
            ->assertNotFound()
            ->assertJsonPath('code', 'facility_missing');
    }

    public function test_the_applicant_endpoints_reject_unauthenticated_callers(): void
    {
        $this->getJson('/api/blood-center/registration-status')->assertUnauthorized();
        $this->postJson('/api/blood-center/registration/resubmit', [])->assertUnauthorized();
    }

    public function test_the_status_endpoint_returns_the_details_needed_to_resubmit(): void
    {
        $facility = Facility::factory()->rejected()->create([
            'address' => 'Quirino Ave, Davao City',
        ]);
        $contact = User::factory()->bloodCenterApplicant($facility)->create();

        // The resubmission form has to send these back, so the status endpoint
        // must supply them.
        $this->actingAs($contact)
            ->getJson('/api/blood-center/registration-status')
            ->assertOk()
            ->assertJsonPath('facility.doh_license_number', $facility->doh_license_number)
            ->assertJsonPath('facility.contact_person', $facility->contact_person)
            ->assertJsonPath('facility.address', 'Quirino Ave, Davao City');
    }
}
