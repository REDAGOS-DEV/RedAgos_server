<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\Department;
use App\Enums\DonationStatus;
use App\Enums\ReferralStatus;
use App\Models\CounsellingReferral;
use App\Models\Donation;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The Testing department's follow-up list for donors with a reactive result.
 *
 * The one screen that names which marker each donor was reactive for, so it
 * is Testing's alone, scoped to the centre that recorded the result, and every
 * read of it is audited.
 */
class CounsellingReferralTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $facility;

    private User $testing;

    private User $donor;

    private Donation $donation;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->testing = User::factory()->bloodCenterStaff($this->facility, Department::Testing)->create();
        $this->donor = User::factory()->donor()->create();

        $this->donation = Donation::factory()->create([
            'facility_id' => $this->facility->id,
            'donor_id' => $this->donor->id,
            'status' => DonationStatus::Collected,
        ]);
    }

    /**
     * Record a reactive panel through the real endpoint, which opens the referral.
     */
    private function reactiveReferral(string $marker = 'hiv'): CounsellingReferral
    {
        $this->actingAs($this->testing)
            ->postJson("/api/blood-center/laboratory/donations/{$this->donation->id}/serology", [
                'hiv' => 'non_reactive',
                'hbsag' => 'non_reactive',
                'hcv' => 'non_reactive',
                'syphilis' => 'non_reactive',
                'malaria' => 'non_reactive',
                $marker => 'reactive',
                'confirm_reactive' => true,
            ])
            ->assertCreated();

        return CounsellingReferral::where('donation_id', $this->donation->id)->firstOrFail();
    }

    public function test_testing_sees_the_referral_with_its_reactive_marker(): void
    {
        $this->reactiveReferral('hcv');

        $this->actingAs($this->testing)
            ->getJson('/api/blood-center/laboratory/referrals')
            ->assertOk()
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.donor.uuid', $this->donor->uuid)
            ->assertJsonPath('data.0.reactive_markers.0.value', 'hcv')
            ->assertJsonPath('data.0.screened_by', trim($this->testing->first_name.' '.$this->testing->last_name));
    }

    public function test_reading_the_list_is_audited(): void
    {
        $this->reactiveReferral();

        $this->actingAs($this->testing)->getJson('/api/blood-center/laboratory/referrals')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->testing->id,
            'action' => 'referral.list_viewed',
        ]);
    }

    public function test_a_supervisor_may_read_the_list(): void
    {
        $supervisor = User::factory()->bloodCenterSupervisor($this->facility)->create();

        $this->actingAs($supervisor)->getJson('/api/blood-center/laboratory/referrals')->assertOk();
    }

    public function test_no_other_department_may_read_the_list(): void
    {
        foreach ([Department::Processing, Department::Collection, Department::Issuance, Department::Billing] as $department) {
            $staff = User::factory()->bloodCenterStaff($this->facility, $department)->create();

            $this->actingAs($staff)
                ->getJson('/api/blood-center/laboratory/referrals')
                ->assertForbidden();
        }
    }

    public function test_another_centres_referrals_are_invisible(): void
    {
        $this->reactiveReferral();

        $otherTesting = User::factory()->bloodCenterStaff(Facility::factory()->approved()->create(), Department::Testing)->create();

        $this->actingAs($otherTesting)
            ->getJson('/api/blood-center/laboratory/referrals?status=all')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $referral = CounsellingReferral::firstOrFail();

        $this->actingAs($otherTesting)
            ->patchJson("/api/blood-center/laboratory/referrals/{$referral->id}", ['status' => 'contacted'])
            ->assertNotFound();
    }

    public function test_a_referral_moves_forward_and_is_stamped(): void
    {
        $referral = $this->reactiveReferral();

        $this->actingAs($this->testing)
            ->patchJson("/api/blood-center/laboratory/referrals/{$referral->id}", [
                'status' => 'contacted',
                'note' => 'Reached by phone; appointment set.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'contacted')
            ->assertJsonPath('data.note', 'Reached by phone; appointment set.');

        $referral->refresh();
        $this->assertSame(ReferralStatus::Contacted, $referral->status);
        $this->assertNotNull($referral->contacted_at);
        $this->assertSame($this->testing->id, (int) $referral->updated_by);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->testing->id,
            'action' => 'referral.status_changed',
        ]);
    }

    public function test_the_note_is_encrypted_at_rest(): void
    {
        $referral = $this->reactiveReferral();

        $this->actingAs($this->testing)
            ->patchJson("/api/blood-center/laboratory/referrals/{$referral->id}", [
                'status' => 'contacted',
                'note' => 'Referred to the social hygiene clinic.',
            ])
            ->assertOk();

        $raw = DB::table('counselling_referrals')->where('id', $referral->id)->value('note');

        $this->assertStringNotContainsString('social hygiene', (string) $raw);
    }

    public function test_a_step_may_be_skipped_but_never_reversed(): void
    {
        $referral = $this->reactiveReferral();

        $this->actingAs($this->testing)
            ->patchJson("/api/blood-center/laboratory/referrals/{$referral->id}", ['status' => 'referred'])
            ->assertOk();

        $this->actingAs($this->testing)
            ->patchJson("/api/blood-center/laboratory/referrals/{$referral->id}", ['status' => 'contacted'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'referral_transition_invalid');

        $this->actingAs($this->testing)
            ->patchJson("/api/blood-center/laboratory/referrals/{$referral->id}", ['status' => 'pending'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_closing_needs_a_note_and_is_final(): void
    {
        $referral = $this->reactiveReferral();

        $this->actingAs($this->testing)
            ->patchJson("/api/blood-center/laboratory/referrals/{$referral->id}", ['status' => 'closed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->actingAs($this->testing)
            ->patchJson("/api/blood-center/laboratory/referrals/{$referral->id}", [
                'status' => 'closed',
                'note' => 'Could not be reached after three attempts.',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_open', false);

        $this->actingAs($this->testing)
            ->patchJson("/api/blood-center/laboratory/referrals/{$referral->id}", [
                'status' => 'closed',
                'note' => 'Again.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'referral_closed');
    }

    public function test_closed_referrals_leave_the_default_list(): void
    {
        $referral = $this->reactiveReferral();

        $this->actingAs($this->testing)
            ->patchJson("/api/blood-center/laboratory/referrals/{$referral->id}", [
                'status' => 'closed',
                'note' => 'Referred and confirmed attended.',
            ])
            ->assertOk();

        $this->actingAs($this->testing)
            ->getJson('/api/blood-center/laboratory/referrals')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->testing)
            ->getJson('/api/blood-center/laboratory/referrals?status=closed')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_closing_a_referral_does_not_lift_the_deferral(): void
    {
        $referral = $this->reactiveReferral();
        $collection = User::factory()->bloodCenterStaff($this->facility, Department::Collection)->create();

        $this->actingAs($this->testing)
            ->patchJson("/api/blood-center/laboratory/referrals/{$referral->id}", [
                'status' => 'closed',
                'note' => 'Counselled.',
            ])
            ->assertOk();

        $this->actingAs($collection)
            ->getJson("/api/blood-center/donors/{$this->donor->uuid}/history")
            ->assertOk()
            ->assertJsonPath('donations.0.laboratory_deferral.outcome', 'permanently_deferred');
    }
}
