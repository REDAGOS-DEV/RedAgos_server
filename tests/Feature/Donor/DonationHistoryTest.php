<?php

namespace Tests\Feature\Donor;

use App\Enums\RoleName;
use App\Models\Donation;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DonationHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $donor;

    private Facility $center;

    protected function setUp(): void
    {
        parent::setUp();

        $this->donor = User::factory()->donor()->create();
        $this->center = Facility::factory()->create();
    }

    public function test_a_donor_sees_their_donation_history(): void
    {
        Donation::factory()->completedAt('2026-03-01')->create([
            'donor_id' => $this->donor->id,
            'facility_id' => $this->center->id,
            'volume_ml' => 450,
        ]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/donations')
            ->assertOk()
            ->assertJsonCount(1, 'donations')
            ->assertJsonPath('donations.0.center_name', $this->center->name)
            ->assertJsonPath('donations.0.volume_ml', 450)
            ->assertJsonPath('donations.0.donated_on', '2026-03-01')
            ->assertJsonPath('donations.0.status', 'completed');
    }

    public function test_history_is_ordered_most_recent_first(): void
    {
        Donation::factory()->completedAt('2026-01-01')->create(['donor_id' => $this->donor->id]);
        Donation::factory()->completedAt('2026-05-01')->create(['donor_id' => $this->donor->id]);
        Donation::factory()->completedAt('2026-03-01')->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/donations')
            ->assertOk()
            ->assertJsonPath('donations.0.donated_on', '2026-05-01')
            ->assertJsonPath('donations.1.donated_on', '2026-03-01')
            ->assertJsonPath('donations.2.donated_on', '2026-01-01');
    }

    public function test_statistics_count_only_completed_donations(): void
    {
        Donation::factory()->count(3)->completedAt('2026-03-01')->create(['donor_id' => $this->donor->id]);
        Donation::factory()->rejected()->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/donations')
            ->assertOk()
            ->assertJsonPath('stats.total_donations', 3)
            ->assertJsonPath('stats.lives_impacted', 9);
    }

    public function test_a_donor_never_sees_another_donors_history(): void
    {
        $other = User::factory()->donor()->create();
        Donation::factory()->completedAt('2026-03-01')->create(['donor_id' => $other->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/donations')
            ->assertOk()
            ->assertJsonCount(0, 'donations')
            ->assertJsonPath('stats.total_donations', 0);
    }

    public function test_history_can_be_filtered_by_status(): void
    {
        Donation::factory()->completedAt('2026-03-01')->create(['donor_id' => $this->donor->id]);
        Donation::factory()->rejected()->create([
            'donor_id' => $this->donor->id,
            'donation_date' => '2026-04-01',
        ]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/donations?status=rejected')
            ->assertOk()
            ->assertJsonCount(1, 'donations')
            ->assertJsonPath('donations.0.status', 'rejected');
    }

    public function test_history_can_be_filtered_by_date_range(): void
    {
        Donation::factory()->completedAt('2026-01-15')->create(['donor_id' => $this->donor->id]);
        Donation::factory()->completedAt('2026-06-15')->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/donations?from=2026-05-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonCount(1, 'donations')
            ->assertJsonPath('donations.0.donated_on', '2026-06-15');
    }

    public function test_history_is_paginated(): void
    {
        Donation::factory()->count(5)->completedAt('2026-03-01')->create(['donor_id' => $this->donor->id]);

        $this->actingAs($this->donor)
            ->getJson('/api/donors/donations?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'donations')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_an_invalid_date_range_is_rejected(): void
    {
        $this->actingAs($this->donor)
            ->getJson('/api/donors/donations?from=2026-06-01&to=2026-01-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_an_unknown_status_filter_is_rejected(): void
    {
        $this->actingAs($this->donor)
            ->getJson('/api/donors/donations?status=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_history_rejects_a_non_donor(): void
    {
        $admin = User::factory()->withRole(RoleName::Admin)->create();

        $this->actingAs($admin)->getJson('/api/donors/donations')->assertForbidden();
    }

    public function test_history_rejects_unauthenticated_callers(): void
    {
        $this->getJson('/api/donors/donations')->assertUnauthorized();
    }
}
