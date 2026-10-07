<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\CorrectionSubject;
use App\Enums\StaffRole;
use App\Models\BloodUnit;
use App\Models\CorrectionRequest;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Rolling back the migration that lets a correction target more than a donation.
 *
 * Run inside the test's own transaction, which SQLite allows for DDL, so that
 * the shared in-memory schema is restored when each test ends. This covers
 * SQLite; rollback on PostgreSQL, where the named CHECK constraint, foreign
 * keys and indexes must come off in order, is checked by hand against a
 * scratch database.
 */
class CorrectionTargetsMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TARGET_COLUMNS = ['blood_unit_id', 'request_allocation_id', 'payment_id'];

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_08_100001_add_issuance_and_billing_targets_to_correction_requests_table.php');
    }

    private function donationCorrection(): CorrectionRequest
    {
        $staff = User::factory()->bloodCenterStaff(null, StaffRole::Phlebotomist)->create();
        $donation = Donation::factory()->create(['facility_id' => $staff->facility_id]);

        return CorrectionRequest::create([
            'facility_id' => $staff->facility_id,
            'donation_id' => $donation->id,
            'subject' => CorrectionSubject::Collection,
            'requested_by' => $staff->id,
            'reason' => 'Wrong volume.',
            'changes' => ['volume_ml' => 450],
            'previous' => ['volume_ml' => 350],
        ]);
    }

    public function test_the_target_columns_exist_after_migrating(): void
    {
        foreach (self::TARGET_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('correction_requests', $column));
        }
    }

    public function test_rolling_back_is_refused_while_a_unit_correction_exists_and_changes_nothing(): void
    {
        $clerk = User::factory()->bloodCenterStaff(null, StaffRole::ItDataClerk)->create();
        $unit = BloodUnit::factory()->create(['facility_id' => $clerk->facility_id]);

        CorrectionRequest::create([
            'facility_id' => $clerk->facility_id,
            'blood_unit_id' => $unit->id,
            'subject' => CorrectionSubject::UnitDetails,
            'requested_by' => $clerk->id,
            'reason' => 'Wrong shelf.',
            'changes' => ['storage_location' => 'Freezer B'],
            'previous' => ['storage_location' => 'Cold Storage A-1'],
        ]);

        try {
            $this->migration()->down();
            $this->fail('A correction with no donation must stop the rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back', $exception->getMessage());
        }

        // The refusal comes before any schema change.
        foreach (self::TARGET_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('correction_requests', $column));
        }

        $this->assertSame(1, DB::table('correction_requests')->count());
    }

    public function test_rolling_back_and_forward_again_keeps_donation_corrections(): void
    {
        $correction = $this->donationCorrection();

        $this->migration()->down();

        foreach (self::TARGET_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('correction_requests', $column), "{$column} must be gone after rolling back.");
        }

        $this->assertSame(1, DB::table('correction_requests')->where('id', $correction->id)->count());

        // donation_id is required again.
        try {
            DB::table('correction_requests')->insert([
                'facility_id' => $correction->facility_id,
                'donation_id' => null,
                'subject' => 'collection',
                'requested_by' => $correction->requested_by,
                'reason' => 'x',
                'changes' => '{}',
                'status' => 'pending',
            ]);
            $this->fail('donation_id must be NOT NULL after rolling back.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->migration()->up();

        foreach (self::TARGET_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('correction_requests', $column), "{$column} must be back after migrating again.");
        }

        $this->assertSame(1, DB::table('correction_requests')->where('id', $correction->id)->count());
    }
}
