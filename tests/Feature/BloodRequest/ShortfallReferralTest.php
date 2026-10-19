<?php

namespace Tests\Feature\BloodRequest;

use App\Models\AuditLog;
use App\Notifications\BloodRequestDecided;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\BloodRequest\Concerns\BuildsFulfilmentScenarios;
use Tests\TestCase;

/**
 * When a centre cannot supply everything (project owner, 2026-10-19): the
 * hospital is told at once, and the watcher is handed a referral slip naming
 * the patient's request and what is still needed elsewhere.
 */
class ShortfallReferralTest extends TestCase
{
    use BuildsFulfilmentScenarios, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_closing_a_remainder_as_unavailable_tells_the_hospital_what_and_how_much(): void
    {
        Notification::fake();

        $request = $this->scenarioRequest();
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 2);
        $this->allocateAndRelease($request);

        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$request->id}/items/{$this->lineFor($request, $this->platelets)}/close", [
                'note' => 'No platelets on the shelf',
            ])
            ->assertOk();

        Notification::assertSentTo($this->requester, BloodRequestDecided::class, function (BloodRequestDecided $notice): bool {
            $data = $notice->toDatabase($this->requester);

            return $data['outcome'] === 'closed_short'
                && $data['tone'] === 'warning'
                && str_contains($data['desc'], '1 unit(s) of Platelet Concentrate')
                && str_contains($data['desc'], 'No platelets on the shelf')
                && str_contains($data['desc'], 'Ask another facility');
        });
    }

    public function test_the_referral_slip_names_the_patients_request_and_what_is_still_needed(): void
    {
        $requirement = $this->recordTransfusion(
            [[$this->prbc, 3], [$this->ffp, 1]],
            [[$this->centre, [[$this->prbc, 3], [$this->ffp, 1]]]],
        );
        $mine = $this->allocationAt($requirement, $this->centre);
        $this->stock($this->centre, $this->prbc, 2);
        $this->stock($this->centre, $this->ffp, 1);

        $this->actingAs($this->issuance)->postJson("/api/blood-center/blood-requests/{$mine->id}/allocate")->assertOk();
        $this->actingAs($this->issuance)
            ->postJson("/api/blood-center/blood-requests/{$mine->id}/items/{$this->lineFor($mine, $this->prbc)}/close")
            ->assertOk();

        $slip = $this->actingAs($this->issuance)
            ->getJson("/api/blood-center/blood-requests/{$mine->id}/referral-slip")
            ->assertOk()
            ->assertJsonPath('reference_number', $requirement->reference_number)
            ->assertJsonPath('request_reference', $mine->reference_number)
            ->assertJsonPath('hospital', 'Southern Hospital Blood Bank')
            ->assertJsonPath('centre', 'Davao Blood Center')
            ->assertJsonPath('patient.full_name', 'DELA CRUZ, Juan')
            ->assertJsonPath('blood_type', 'O+')
            ->assertJsonPath('still_needed', 1);

        $lines = collect($slip->json('lines'))->keyBy('component');
        $this->assertSame(1, $lines['Packed RBC']['unallocated']);
        $this->assertSame(0, $lines['Fresh Frozen Plasma']['unallocated']);

        // It leaves the building with the patient's name, so it is audited.
        $this->assertTrue(AuditLog::query()->where('action', 'request.referral_slip_printed')->exists());
    }

    public function test_there_is_no_slip_for_a_request_that_is_not_a_patients(): void
    {
        $request = $this->scenarioRequest();

        $this->actingAs($this->issuance)
            ->getJson("/api/blood-center/blood-requests/{$request->id}/referral-slip")
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_patient_request');
    }
}
