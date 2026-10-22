<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\StaffRole;
use App\Models\Facility;
use App\Models\User;
use App\Service\StockAlertNotifier;
use App\Support\StaffNotificationTopics;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The notices a blood-centre staff member gets, as their Settings screen shows
 * them, and the one they may turn off.
 */
class StaffNotificationPreferencesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $centre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Facility::factory()->approved()->create();
    }

    /**
     * @return array<int, string>
     */
    private function topicKeys(User $user): array
    {
        return collect($this->actingAs($user)
            ->getJson('/api/blood-center/notification-preferences')
            ->assertOk()
            ->json('topics'))->pluck('key')->all();
    }

    public function test_an_inventory_officer_gets_the_tasks_always_and_the_watches_by_choice(): void
    {
        $officer = User::factory()->bloodCenterStaff($this->centre, StaffRole::InventoryControlOfficer)->create();

        $topics = collect($this->actingAs($officer)
            ->getJson('/api/blood-center/notification-preferences')
            ->assertOk()
            ->json('topics'))->keyBy('key');

        // Also Issuance's correction approver.
        $this->assertSame([
            StaffNotificationTopics::BLOOD_REQUESTS,
            StaffNotificationTopics::CORRECTIONS,
            StaffNotificationTopics::LOW_STOCK,
            StaffNotificationTopics::EXPIRING_UNITS,
        ], $topics->keys()->all());
        $this->assertTrue($topics[StaffNotificationTopics::BLOOD_REQUESTS]['locked']);
        $this->assertTrue($topics[StaffNotificationTopics::CORRECTIONS]['locked']);
        $this->assertFalse($topics[StaffNotificationTopics::EXPIRING_UNITS]['locked']);
        $this->assertFalse($topics[StaffNotificationTopics::LOW_STOCK]['locked']);
        $this->assertTrue($topics[StaffNotificationTopics::LOW_STOCK]['enabled']);
    }

    public function test_the_lab_supervisor_gets_results_to_verify_and_waiting_referrals(): void
    {
        $lab = User::factory()->bloodCenterStaff($this->centre, StaffRole::LabSupervisor)->create();

        $this->assertContains(StaffNotificationTopics::SEROLOGY_VERIFICATION, $this->topicKeys($lab));
        $this->assertContains(StaffNotificationTopics::REFERRALS, $this->topicKeys($lab));
        $this->assertNotContains(StaffNotificationTopics::BLOOD_REQUESTS, $this->topicKeys($lab));
    }

    public function test_the_receptionist_may_turn_off_tomorrows_drive(): void
    {
        $receptionist = User::factory()->bloodCenterStaff($this->centre, StaffRole::MedicalReceptionist)->create();

        $this->assertSame([StaffNotificationTopics::DRIVE_REMINDERS], $this->topicKeys($receptionist));

        $this->actingAs($receptionist)
            ->patchJson('/api/blood-center/notification-preferences', ['drive_reminders' => false])
            ->assertOk()
            ->assertJsonPath('topics.0.enabled', false);

        $this->assertFalse(StaffNotificationTopics::receivesDriveReminders($receptionist->fresh()));
    }

    public function test_a_billing_clerk_gets_none_of_these(): void
    {
        $clerk = User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create();

        $this->assertSame([], $this->topicKeys($clerk));
    }

    public function test_the_supervisor_watches_requests_and_stock(): void
    {
        $supervisor = User::factory()->bloodCenterSupervisor($this->centre)->create();

        $topics = collect($this->actingAs($supervisor)
            ->getJson('/api/blood-center/notification-preferences')
            ->json('topics'))->keyBy('key');

        $this->assertStringContainsString('Supervisor', $topics[StaffNotificationTopics::BLOOD_REQUESTS]['reason']);
        $this->assertTrue($topics->has(StaffNotificationTopics::LOW_STOCK));
    }

    public function test_turning_low_stock_off_stops_the_alert_and_nothing_else(): void
    {
        $officer = User::factory()->bloodCenterStaff($this->centre, StaffRole::InventoryControlOfficer)->create();
        $dispatcher = User::factory()->bloodCenterStaff($this->centre, StaffRole::DispatchCoordinator)->create();

        $this->actingAs($officer)
            ->patchJson('/api/blood-center/notification-preferences', [
                'low_stock' => false,
                // Always on: not a switch, so ignored.
                'blood_requests' => false,
            ])
            ->assertOk();

        $topics = collect($this->actingAs($officer)->getJson('/api/blood-center/notification-preferences')->json('topics'))->keyBy('key');
        $this->assertFalse($topics[StaffNotificationTopics::LOW_STOCK]['enabled']);
        $this->assertTrue($topics[StaffNotificationTopics::EXPIRING_UNITS]['enabled']);
        $this->assertFalse($officer->fresh()->notification_preferences['low_stock']);
        $this->assertTrue(StaffNotificationTopics::receivesRequests($officer->fresh()));

        $recipients = app(StockAlertNotifier::class)->recipients($this->centre)->pluck('id')->all();
        $this->assertNotContains($officer->id, $recipients);
        $this->assertContains($dispatcher->id, $recipients);
    }

    public function test_a_switch_must_be_a_boolean(): void
    {
        $officer = User::factory()->bloodCenterStaff($this->centre, StaffRole::InventoryControlOfficer)->create();

        $this->actingAs($officer)
            ->patchJson('/api/blood-center/notification-preferences', ['low_stock' => 'off'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('low_stock');
    }

    public function test_a_donor_cannot_reach_it(): void
    {
        $this->actingAs(User::factory()->donor()->create())
            ->getJson('/api/blood-center/notification-preferences')
            ->assertForbidden();
    }
}
