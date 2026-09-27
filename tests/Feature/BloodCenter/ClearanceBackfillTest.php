<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Models\DonationClearance;
use App\Models\DonationImmunohematology;
use App\Models\DonationSerology;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ClearanceBackfillTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_09_28_100002_create_donation_clearances_table.php');

        $migration->backfill();
    }

    private function kinds(Donation $donation): array
    {
        return DonationClearance::where('donation_id', $donation->id)->orderBy('kind')->pluck('kind')
            ->map(fn ($kind): string => $kind->value)->all();
    }

    private function sections(Donation $donation, string $hiv = 'non_reactive', bool $typed = true, bool $screened = true): void
    {
        $staff = User::factory()->bloodCenterStaff()->create();

        if ($typed) {
            DonationImmunohematology::create([
                'donation_id' => $donation->id,
                'blood_type_id' => $donation->donorProfile->blood_type_id,
                'recorded_by' => $staff->id,
                'recorded_at' => now(),
            ]);
        }

        if ($screened) {
            DonationSerology::create([
                'donation_id' => $donation->id,
                'hiv' => $hiv, 'hbsag' => 'non_reactive', 'hcv' => 'non_reactive',
                'syphilis' => 'non_reactive', 'malaria' => 'non_reactive',
                'recorded_by' => $staff->id,
                'recorded_at' => now(),
            ]);
        }
    }

    public function test_a_completed_donation_keeps_its_units_issuable(): void
    {
        $donation = Donation::factory()->create(['status' => DonationStatus::Completed]);

        $this->runBackfill();

        $this->assertSame(['immunohematology', 'tti'], $this->kinds($donation));
    }

    public function test_a_tested_donation_with_both_sections_gets_both_tokens(): void
    {
        $donation = Donation::factory()->create(['status' => DonationStatus::Tested]);
        $this->sections($donation);

        $this->runBackfill();

        $this->assertSame(['immunohematology', 'tti'], $this->kinds($donation));
        $this->assertSame(DonationStatus::Tested, $donation->fresh()->status);
    }

    public function test_a_legacy_tested_donation_without_a_panel_goes_back_to_collected(): void
    {
        $donation = Donation::factory()->create(['status' => DonationStatus::Tested]);
        $this->sections($donation, screened: false);

        $this->runBackfill();

        $this->assertSame(['immunohematology'], $this->kinds($donation));
        $this->assertSame(DonationStatus::Collected, $donation->fresh()->status);
    }

    public function test_collected_and_rejected_donations_get_nothing(): void
    {
        $collected = Donation::factory()->create(['status' => DonationStatus::Collected]);
        $rejected = Donation::factory()->create(['status' => DonationStatus::Rejected]);
        $this->sections($collected);

        $this->runBackfill();

        $this->assertSame([], $this->kinds($collected));
        $this->assertSame([], $this->kinds($rejected));
    }

    public function test_it_is_safe_to_run_twice(): void
    {
        $donation = Donation::factory()->create(['status' => DonationStatus::Completed]);

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(2, DonationClearance::where('donation_id', $donation->id)->count());
    }
}
