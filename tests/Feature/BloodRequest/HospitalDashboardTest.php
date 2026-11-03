<?php

namespace Tests\Feature\BloodRequest;

use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodType;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The hospital dashboard: its own facility's requests, in the shape the page reads.
 */
class HospitalDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Facility $hospital;

    private Facility $centre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hospital = Facility::factory()->bloodBank()->approved()->create();
        $this->centre = Facility::factory()->approved()->create();
    }

    public function test_it_counts_only_this_blood_banks_requests(): void
    {
        $mine = $this->raise($this->hospital);
        $this->raise($this->hospital, ['status' => 'processing']);
        $this->raise($this->hospital, ['status' => 'fulfilled']);
        $this->raise($this->hospital, ['urgency_level' => 'emergency']);
        $this->raise(Facility::factory()->bloodBank()->approved()->create());

        $response = $this->actingAs(User::factory()->bloodBankStaff($this->hospital)->create())
            ->getJson('/api/hospital/dashboard')
            ->assertOk()
            ->assertJsonPath('pending_requests', 2)
            ->assertJsonPath('approved_requests', 1)
            ->assertJsonPath('completed_today', 1)
            ->assertJsonPath('emergency_requests', 1)
            ->assertJsonCount(4, 'recent_requests')
            ->assertJsonCount(7, 'weekly_trend')
            ->assertJsonCount(5, 'monthly_trend')
            ->assertJsonCount(12, 'yearly_trend')
            ->assertJsonStructure(['units_available', 'activities', 'notifications', 'tasks' => ['to_receive', 'to_stock_in', 'expiring', 'overdue_tags']]);

        $this->assertContains($mine->id, array_column($response->json('recent_requests'), 'id'));
        $this->assertSame(4, array_sum(array_column($response->json('weekly_trend'), 'value')));
    }

    public function test_blood_center_staff_are_refused(): void
    {
        $this->actingAs(User::factory()->bloodCenterStaff($this->centre)->create())
            ->getJson('/api/hospital/dashboard')
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function raise(Facility $facility, array $overrides = []): BloodRequest
    {
        return BloodRequest::factory()
            ->raisedBy($facility)
            ->addressedTo($this->centre)
            ->forStock(BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']), BloodComponent::factory()->create(), 2)
            ->create(['request_date' => now(), ...$overrides]);
    }
}
