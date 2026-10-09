<?php

namespace Tests\Feature\StockThreshold;

use App\Enums\FacilityStatus;
use App\Enums\FacilityTypeName;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Facility;
use App\Models\StockThreshold;
use App\Models\User;
use App\Notifications\LowStockAlert;
use App\Service\StockAlertNotifier;
use App\Service\StockThresholdService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\StockThreshold\Concerns\BuildsThresholdStock;
use Tests\TestCase;

/**
 * The sweep that turns a breached minimum into a notification.
 *
 * The delivery guarantee under test: a low episode is per cell; it is announced
 * once, as one grouped notification per account per facility per run; and the
 * notification rows commit in the same transaction as the cells' `alerted_at`,
 * so a failed send leaves nothing marked and the next run retries it.
 */
class CheckStockThresholdsTest extends TestCase
{
    use BuildsThresholdStock, LazilyRefreshDatabase;

    private Facility $centre;

    private User $officer;

    private User $dispatcher;

    private User $supervisor;

    private User $billing;

    private BloodType $oPositive;

    private BloodType $aPositive;

    private BloodComponent $prbc;

    private BloodComponent $ffp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = Facility::factory()->approved()->create();
        $this->officer = User::factory()->bloodCenterStaff($this->centre, StaffRole::InventoryControlOfficer)->create();
        $this->dispatcher = User::factory()->bloodCenterStaff($this->centre, StaffRole::DispatchCoordinator)->create();
        $this->supervisor = User::factory()->bloodCenterStaff($this->centre)->create(['is_supervisor' => true]);
        $this->billing = User::factory()->bloodCenterStaff($this->centre, StaffRole::BillingClerk)->create();

        $this->oPositive = $this->bloodType('O+');
        $this->aPositive = $this->bloodType('A+');
        $this->prbc = BloodComponent::factory()->create(['name' => 'Packed RBC']);
        $this->ffp = BloodComponent::factory()->create(['name' => 'Fresh Frozen Plasma']);
    }

    public function test_a_low_cell_notifies_every_inventory_viewer_once(): void
    {
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 2);
        $threshold = $this->threshold($this->centre, $this->oPositive, $this->prbc, 5);

        $this->sweep();

        foreach ([$this->officer, $this->dispatcher, $this->supervisor] as $user) {
            $this->assertCount(1, $this->alertsFor($user), "{$user->staff_role?->value} should have been told once.");
        }

        // No ability to see the inventory, no alert about it.
        $this->assertCount(0, $this->alertsFor($this->billing));

        $data = $this->alertsFor($this->officer)->first()->data;
        $this->assertSame('inventory', $data['category']);
        $this->assertSame('Low stock: O+ Packed RBC', $data['title']);
        $this->assertSame('O+ Packed RBC 2 of 5', $data['desc']);
        $this->assertSame('warning', $data['tone']);
        $this->assertSame('/blood-center/stock-thresholds', $data['action_route']);
        $this->assertSame($this->centre->id, $data['facility_id']);

        $this->assertNotNull($threshold->fresh()->alerted_at);

        $audit = AuditLog::query()->where('action', 'stock_threshold.alerted')->sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame(3, $audit->context['notified_accounts']);
        $this->assertSame('O+', $audit->context['items'][0]['blood_type_code']);
        $this->assertSame('schedule:inventory:check-thresholds', $audit->context['source']);
    }

    public function test_an_empty_shelf_is_critical_and_danger_toned(): void
    {
        $this->threshold($this->centre, $this->oPositive, $this->prbc, 4);

        $this->sweep();

        $data = $this->alertsFor($this->officer)->sole()->data;
        $this->assertSame('danger', $data['tone']);
        $this->assertSame('O+ Packed RBC 0 of 4', $data['desc']);
        $this->assertSame('critical', $data['items'][0]['status']);
    }

    public function test_several_cells_in_one_run_make_one_grouped_notification_per_account(): void
    {
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 2);
        $this->threshold($this->centre, $this->oPositive, $this->prbc, 5);
        $this->threshold($this->centre, $this->aPositive, $this->ffp, 3);

        $this->sweep();

        $alerts = $this->alertsFor($this->officer);
        $this->assertCount(1, $alerts);

        $data = $alerts->first()->data;
        $this->assertSame('Low stock: 2 blood stocks below minimum', $data['title']);
        $this->assertSame('O+ Packed RBC 2 of 5 · A+ Fresh Frozen Plasma 0 of 3', $data['desc']);
        $this->assertSame('danger', $data['tone']);
        $this->assertCount(2, $data['items']);
    }

    public function test_a_cell_that_stays_low_is_not_notified_again(): void
    {
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 2);
        $this->threshold($this->centre, $this->oPositive, $this->prbc, 5);

        $this->sweep();
        $this->sweep();
        $this->sweep();

        $this->assertCount(1, $this->alertsFor($this->officer));
        $this->assertSame(1, AuditLog::query()->where('action', 'stock_threshold.alerted')->count());
    }

    public function test_a_healthy_cell_sends_nothing(): void
    {
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 5);
        $threshold = $this->threshold($this->centre, $this->oPositive, $this->prbc, 5);

        $this->sweep();

        $this->assertCount(0, $this->alertsFor($this->officer));
        $this->assertNull($threshold->fresh()->alerted_at);
    }

    public function test_recovery_ends_the_episode_and_a_new_drop_alerts_again(): void
    {
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 2);
        $threshold = $this->threshold($this->centre, $this->oPositive, $this->prbc, 5);

        $this->sweep();
        $this->assertCount(1, $this->alertsFor($this->officer));

        $restock = $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 4);
        $this->sweep();

        $this->assertNull($threshold->fresh()->alerted_at);
        $this->assertSame('stock_recovered', AuditLog::query()->where('action', 'stock_threshold.recovered')->sole()->context['items'][0]['reason']);
        $this->assertCount(1, $this->alertsFor($this->officer), 'Recovering is not announced.');

        // Stock drops again: a second, separate episode.
        BloodUnit::query()->whereIn('id', array_map(fn (BloodUnit $unit): string => $unit->id, $restock))->update(['status' => 'discarded']);
        $this->sweep();

        $this->assertCount(2, $this->alertsFor($this->officer));
        $this->assertNotNull($threshold->fresh()->alerted_at);
    }

    public function test_alerts_off_sends_nothing_but_the_status_still_shows_the_shortage(): void
    {
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 2);
        StockThreshold::factory()->forCell($this->centre, $this->oPositive, $this->prbc)->minimum(5)->alertsOff()->create();

        $this->sweep();

        $this->assertCount(0, $this->alertsFor($this->officer));

        $this->actingAs($this->officer)
            ->getJson('/api/blood-center/inventory/thresholds')
            ->assertOk()
            ->assertJsonCount(1, 'low')
            ->assertJsonPath('low.0.status', 'low');
    }

    public function test_turning_alerts_off_ends_the_episode_and_on_again_alerts_afresh(): void
    {
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 2);
        $threshold = $this->threshold($this->centre, $this->oPositive, $this->prbc, 5);

        $this->sweep();
        $this->assertCount(1, $this->alertsFor($this->officer));

        $this->saveThresholds($this->officer, [$this->cell($this->oPositive, $this->prbc, 5, false)]);
        $this->assertNull($threshold->fresh()->alerted_at);
        $this->assertTrue(AuditLog::query()->where('action', 'stock_threshold.saved')->latest('id')->first()->context['episode_reset']);

        $this->sweep();
        $this->assertCount(1, $this->alertsFor($this->officer), 'Muted cells stay quiet.');

        $this->saveThresholds($this->officer, [$this->cell($this->oPositive, $this->prbc, 5, true)]);
        $this->sweep();

        $this->assertCount(2, $this->alertsFor($this->officer));
    }

    public function test_lowering_a_minimum_to_healthy_then_raising_it_again_starts_a_new_episode(): void
    {
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 2);
        $threshold = $this->threshold($this->centre, $this->oPositive, $this->prbc, 5);

        $this->sweep();
        $this->assertNotNull($threshold->fresh()->alerted_at);
        $this->assertCount(1, $this->alertsFor($this->officer));

        // An admin lowers it to a level the shelf already meets...
        $this->saveThresholds($this->officer, [$this->cell($this->oPositive, $this->prbc, 1)]);
        $this->assertNull($threshold->fresh()->alerted_at, 'The first episode ended when the cell stopped being breached.');

        $audit = AuditLog::query()->where('action', 'stock_threshold.saved')->latest('id')->first();
        $this->assertTrue($audit->context['episode_reset']);

        // ...and raises it again before any sweep has run in between.
        $this->saveThresholds($this->officer, [$this->cell($this->oPositive, $this->prbc, 5)]);
        $this->sweep();

        $this->assertCount(2, $this->alertsFor($this->officer), 'The second shortage must notify.');
        $this->assertNotNull($threshold->fresh()->alerted_at);
    }

    public function test_changing_a_minimum_that_is_still_breached_keeps_the_same_episode(): void
    {
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 2);
        $threshold = $this->threshold($this->centre, $this->oPositive, $this->prbc, 5);

        $this->sweep();
        $alertedAt = $threshold->fresh()->alerted_at;

        $this->saveThresholds($this->officer, [$this->cell($this->oPositive, $this->prbc, 8)]);

        $this->assertEquals($alertedAt, $threshold->fresh()->alerted_at);
        $this->assertFalse(AuditLog::query()->where('action', 'stock_threshold.saved')->latest('id')->first()->context['episode_reset']);

        $this->sweep();
        $this->assertCount(1, $this->alertsFor($this->officer));
    }

    public function test_clearing_an_alerted_threshold_ends_the_episode_with_the_row(): void
    {
        $this->stockCentre($this->centre, $this->oPositive, $this->prbc, 2);
        $this->threshold($this->centre, $this->oPositive, $this->prbc, 5);

        $this->sweep();
        $this->saveThresholds($this->officer, [$this->cell($this->oPositive, $this->prbc, null)]);

        $this->assertDatabaseCount('stock_thresholds', 0);
        $this->assertTrue(AuditLog::query()->where('action', 'stock_threshold.cleared')->sole()->context['episode_reset']);
    }

    public function test_retiring_a_component_deletes_its_thresholds_with_an_audit(): void
    {
        $retired = BloodComponent::factory()->create(['name' => 'Retired Product']);
        $this->threshold($this->centre, $this->oPositive, $retired, 5);
        $kept = $this->threshold($this->centre, $this->oPositive, $this->prbc, 5);

        $retired->delete();

        $this->assertDatabaseMissing('stock_thresholds', ['component_id' => $retired->id]);
        $this->assertDatabaseHas('stock_thresholds', ['id' => $kept->id]);

        $audit = AuditLog::query()->where('action', 'stock_threshold.cleared')->sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame('component_retired', $audit->context['reason']);
        $this->assertSame($this->centre->id, $audit->context['facility_id']);
        $this->assertSame($retired->id, $audit->context['component_id']);
    }

    public function test_a_threshold_on_a_component_retired_without_events_is_inert(): void
    {
        $retired = BloodComponent::factory()->create(['name' => 'Retired Product']);
        $threshold = $this->threshold($this->centre, $this->oPositive, $retired, 5);

        // Retired by a route that never fired the model event: the row survives.
        BloodComponent::withoutEvents(fn () => $retired->delete());
        $this->assertDatabaseHas('stock_thresholds', ['id' => $threshold->id]);

        // Invisible in the grid...
        $response = $this->actingAs($this->officer)->getJson('/api/blood-center/inventory/thresholds')->assertOk();
        $this->assertNotContains('Retired Product', array_column($response->json('components'), 'name'));
        $this->assertNotContains('Retired Product', array_column($response->json('cells'), 'component_name'));

        // ...ignored when choosing what to sweep...
        $this->assertSame([], app(StockThresholdService::class)->facilityIdsToSweep());

        // ...and never alerts, so there is no alert nobody can see or edit.
        $this->sweep();
        $this->assertCount(0, $this->alertsFor($this->officer));
        $this->assertNull($threshold->fresh()->alerted_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'stock_threshold.alerted')->count());
    }

    public function test_a_failed_send_rolls_the_marks_back_and_the_next_run_retries_it(): void
    {
        $otherCentre = Facility::factory()->approved()->create();
        $otherOfficer = User::factory()->bloodCenterStaff($otherCentre)->create();

        $failing = $this->threshold($this->centre, $this->oPositive, $this->prbc, 5);
        $working = $this->threshold($otherCentre, $this->oPositive, $this->prbc, 5);

        $real = new StockAlertNotifier;
        $failingFacilityId = $this->centre->id;

        $this->partialMock(StockAlertNotifier::class, function (MockInterface $mock) use ($real, $failingFacilityId): void {
            $mock->shouldReceive('send')->andReturnUsing(function (Facility $facility, array $items) use ($real, $failingFacilityId): int {
                if ($facility->id === $failingFacilityId) {
                    throw new RuntimeException('notification store unavailable');
                }

                return $real->send($facility, $items);
            });
        });

        $this->artisan('inventory:check-thresholds')->assertFailed();

        // The failed facility: nothing marked, nothing sent, nothing audited.
        $this->assertNull($failing->fresh()->alerted_at, 'A failed send must not suppress the episode.');
        $this->assertCount(0, $this->alertsFor($this->officer));
        $this->assertSame(1, AuditLog::query()->where('action', 'stock_threshold.alerted')->count());

        // A failure at one facility does not stop the others.
        $this->assertNotNull($working->fresh()->alerted_at);
        $this->assertCount(1, $this->alertsFor($otherOfficer));

        // The next run, with the store healthy again, sends exactly once.
        $this->instance(StockAlertNotifier::class, new StockAlertNotifier);
        $this->sweep();

        $this->assertNotNull($failing->fresh()->alerted_at);
        $this->assertCount(1, $this->alertsFor($this->officer));
        $this->assertCount(1, $this->alertsFor($otherOfficer), 'The healthy facility is not told twice.');
    }

    public function test_a_facility_that_may_not_operate_is_not_swept(): void
    {
        $threshold = $this->threshold($this->centre, $this->oPositive, $this->prbc, 5);

        $this->centre->forceFill(['status' => FacilityStatus::Rejected])->save();

        $this->sweep();

        $this->assertCount(0, $this->alertsFor($this->officer));
        $this->assertNull($threshold->fresh()->alerted_at);
    }

    public function test_the_alert_is_database_only_and_not_queued(): void
    {
        // The atomic delivery guarantee depends on both: a queued or mailed
        // channel would commit alerted_at before the send had happened.
        $alert = new LowStockAlert(FacilityTypeName::BloodCenter, $this->centre->id, []);

        $this->assertSame(['database'], $alert->via($this->officer));
        $this->assertNotInstanceOf(ShouldQueue::class, $alert);
    }

    public function test_a_hospital_alert_reaches_every_account_of_that_hospital_only(): void
    {
        $hospital = Facility::factory()->bloodBank()->approved()->create();
        $first = User::factory()->bloodBankStaff($hospital)->create();
        $second = User::factory()->bloodBankStaff($hospital)->create();
        $elsewhere = User::factory()->bloodBankStaff()->create();

        $this->stockHospital($hospital, $this->oPositive, $this->prbc, 2);
        $this->threshold($hospital, $this->oPositive, $this->prbc, 5);

        $this->sweep();

        $this->assertCount(1, $this->alertsFor($first));
        $this->assertCount(1, $this->alertsFor($second));
        $this->assertCount(0, $this->alertsFor($elsewhere));
        $this->assertCount(0, $this->alertsFor($this->officer));

        $this->assertSame('/hospital/stock-thresholds', $this->alertsFor($first)->first()->data['action_route']);
    }

    public function test_the_hospital_inbox_serves_the_alert_in_the_shape_the_client_reads(): void
    {
        $hospital = Facility::factory()->bloodBank()->approved()->create();
        $staff = User::factory()->bloodBankStaff($hospital)->create();

        // Two separate episodes, a few seconds apart so their order is certain.
        $this->threshold($hospital, $this->oPositive, $this->prbc, 5);
        $this->sweep();

        $this->travel(5)->seconds();

        $this->threshold($hospital, $this->aPositive, $this->prbc, 3);
        $this->sweep();

        $this->actingAs($staff)
            ->getJson('/api/hospital/notifications?per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('notifications.0.category', 'inventory')
            ->assertJsonPath('notifications.0.title', 'Low stock: O+ Packed RBC')
            ->assertJsonPath('notifications.0.desc', 'O+ Packed RBC 0 of 5')
            ->assertJsonPath('notifications.0.action_route', '/hospital/stock-thresholds')
            ->assertJsonPath('notifications.0.action_label', 'View thresholds')
            ->assertJsonPath('notifications.0.tone', 'danger')
            ->assertJsonPath('notifications.0.icon', 'triangle-alert')
            ->assertJsonPath('notifications.0.read', false)
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('unread_count', 2);

        $this->actingAs($staff)
            ->getJson('/api/hospital/notifications/unread-count')
            ->assertOk()
            ->assertExactJson(['unread_count' => 2]);
    }

    private function sweep(): void
    {
        $this->artisan('inventory:check-thresholds')->assertSuccessful();
    }

    private function threshold(Facility $facility, BloodType $type, BloodComponent $component, int $minimum): StockThreshold
    {
        return StockThreshold::factory()->forCell($facility, $type, $component)->minimum($minimum)->create();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    private function alertsFor(User $user): Collection
    {
        return $user->notifications()->where('type', LowStockAlert::class)->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $cells
     */
    private function saveThresholds(User $by, array $cells): void
    {
        $this->actingAs($by)
            ->putJson('/api/blood-center/inventory/thresholds', ['thresholds' => $cells])
            ->assertOk();
    }

    /**
     * @return array{blood_type_id: int, component_id: int, minimum_units: int|null, alerts_enabled?: bool}
     */
    private function cell(BloodType $type, BloodComponent $component, ?int $minimum, ?bool $alertsEnabled = null): array
    {
        $cell = [
            'blood_type_id' => $type->id,
            'component_id' => $component->id,
            'minimum_units' => $minimum,
        ];

        if ($alertsEnabled !== null) {
            $cell['alerts_enabled'] = $alertsEnabled;
        }

        return $cell;
    }
}
