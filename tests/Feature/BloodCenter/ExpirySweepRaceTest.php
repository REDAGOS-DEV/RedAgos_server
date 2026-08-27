<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\BloodUnitStatus;
use App\Models\AuditLog;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\User;
use App\Repository\InventoryRepository;
use App\Service\InventoryService;
use App\Support\OperationalDay;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CompilesPostgresQueries;
use Tests\TestCase;

/**
 * The sweep must never write on the strength of its own selection.
 *
 * Select-then-update is a check separated from its write. A staff member
 * discarding a bag, or correcting a mistyped expiry, in the seconds between the
 * two would have had their decision overwritten by a machine — and an
 * inventory.expired row written for a transition that never legitimately
 * happened. A false entry in an append-only trail is worse than a missing one,
 * because it is indistinguishable from a true one.
 *
 * The guard is tested at two levels, because it is the kind that looks
 * redundant right up until it is deleted. Directly on the repository, which
 * needs no interleaving to be exact; and end to end with the staff action
 * landing inside the window. As with the intake race, the lock itself is not
 * testable under sqlite :memory:, where lockForUpdate() compiles to nothing —
 * it is proven against the pgsql grammar instead, and what the sqlite tests
 * prove is the predicates, which are what stop the bad write even when the lock
 * does nothing.
 */
class ExpirySweepRaceTest extends TestCase
{
    use CompilesPostgresQueries, LazilyRefreshDatabase;

    private User $staff;

    private int $facilityId;

    private BloodType $bloodType;

    private BloodComponent $component;

    private DonorProfile $donorProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->bloodCenterStaff()->create();
        $this->facilityId = $this->staff->facility_id;

        $this->bloodType = BloodType::firstOrCreate(['code' => 'O+'], ['label' => 'O+']);
        $this->component = BloodComponent::factory()->create(['name' => 'Packed RBC']);

        $this->donorProfile = DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $this->bloodType->id,
        ]);
    }

    public function test_the_sweep_asks_for_the_row_lock_before_it_writes(): void
    {
        $queries = $this->compiledOnPostgres(
            fn () => app(InventoryRepository::class)->lockConfirmedDueUnits(['DUE-01'], OperationalDay::todayAsDate())
        );

        $this->assertStringEndsWith('for update', $queries[0]['query']);
    }

    public function test_the_confirmation_query_drops_everything_staff_has_touched(): void
    {
        $this->seedCandidates();

        $confirmed = app(InventoryRepository::class)->lockConfirmedDueUnits(
            ['DUE-01', 'GONE-01', 'FIXED-01', 'EXP-01'],
            OperationalDay::todayAsDate(),
        );

        // Both predicates, not just the status: correcting a mistyped expiry to
        // a future date is the second way staff can legitimately take a unit out
        // of scope, and only the date predicate catches that one.
        $this->assertSame(['DUE-01'], $confirmed->pluck('id')->all());
    }

    public function test_the_update_carries_the_same_predicates_as_the_lock(): void
    {
        $this->seedCandidates();

        $sweptAt = OperationalDay::today();

        // Deliberately handed the unconfirmed list: this is the statement
        // defending itself, which is what protects it if someone later
        // refactors the lock away.
        $affected = app(InventoryRepository::class)->markExpired(
            ['DUE-01', 'GONE-01', 'FIXED-01', 'EXP-01'],
            OperationalDay::todayAsDate(),
            $sweptAt,
        );

        $this->assertSame(1, $affected);
        $this->assertSame(BloodUnitStatus::Expired, $this->unit('DUE-01')->status);
        $this->assertNotNull($this->unit('DUE-01')->expired_at);

        $this->assertSame(BloodUnitStatus::Discarded, $this->unit('GONE-01')->status);
        $this->assertNull($this->unit('GONE-01')->expired_at);
        $this->assertSame(BloodUnitStatus::Available, $this->unit('FIXED-01')->status);
        // Already expired, so its own expired_at is not restamped by a later run.
        $this->assertTrue($this->unit('EXP-01')->expired_at->lessThan($sweptAt));
    }

    public function test_a_unit_discarded_inside_the_window_is_not_expired_by_the_sweep(): void
    {
        $this->seedDueUnits(['DUE-01', 'DUE-02', 'DUE-03']);

        $this->whenTheSweepSelectsCandidates(function (): void {
            app(InventoryService::class)->discard($this->staff, 'DUE-02', 'Seal broken during handling');
        });

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $victim = $this->unit('DUE-02');

        // The staff decision stands, and — the specific thing being asserted
        // absent — no audit row claims a machine expired this bag.
        $this->assertSame(BloodUnitStatus::Discarded, $victim->status);
        $this->assertSame('Seal broken during handling', $victim->discard_reason);
        $this->assertNull($victim->expired_at);
        $this->assertFalse($this->hasExpiredAuditRow('DUE-02'));

        $this->assertSame(BloodUnitStatus::Expired, $this->unit('DUE-01')->status);
        $this->assertSame(BloodUnitStatus::Expired, $this->unit('DUE-03')->status);

        // The run row counts what was confirmed, not what was selected.
        $this->assertSame(2, $this->sweptRow()->context['expired_count']);
        $this->assertSame(2, $this->sweptRow()->context['by_facility'][$this->facilityId]);
        $this->assertSame(2, AuditLog::query()->where('action', 'inventory.expired')->count());
    }

    public function test_a_unit_whose_expiry_was_corrected_inside_the_window_is_not_expired(): void
    {
        $this->seedDueUnits(['DUE-01', 'DUE-02', 'DUE-03']);

        $corrected = OperationalDay::today()->addDays(30)->toDateString();

        // The case only the date predicate catches: the unit is still
        // available, so a status-only re-check would have expired it anyway.
        $this->whenTheSweepSelectsCandidates(function () use ($corrected): void {
            app(InventoryService::class)->update($this->staff, 'DUE-02', ['expiry_date' => $corrected]);
        });

        $this->artisan('inventory:expire-units')->assertSuccessful();

        $victim = $this->unit('DUE-02');

        $this->assertSame(BloodUnitStatus::Available, $victim->status);
        $this->assertSame($corrected, $victim->expiry_date->toDateString());
        $this->assertNull($victim->expired_at);
        $this->assertFalse($this->hasExpiredAuditRow('DUE-02'));

        $this->assertSame(2, $this->sweptRow()->context['expired_count']);
    }

    /**
     * Run a staff write in the window between the candidate select and the update.
     *
     * The sweep selects its candidates outside the transaction it then opens, so
     * a listener on that select lands in exactly the gap D11 closes. The flag is
     * set before the action runs because the action's own queries come back
     * through this same listener.
     */
    private function whenTheSweepSelectsCandidates(callable $staffAction): void
    {
        $fired = false;

        DB::listen(function (QueryExecuted $query) use (&$fired, $staffAction): void {
            if ($fired || ! $this->isCandidateSelect($query->sql)) {
                return;
            }

            $fired = true;

            $staffAction();
        });
    }

    /**
     * Whether this is the sweep's chunked candidate select.
     */
    private function isCandidateSelect(string $sql): bool
    {
        return str_contains($sql, 'from "blood_units"')
            && str_contains($sql, 'limit 500');
    }

    /**
     * One unit in each state the confirmation query has to separate.
     */
    private function seedCandidates(): void
    {
        $this->makeUnit(['id' => 'DUE-01', 'expiry_date' => $this->inDays(-1)]);
        $this->makeUnit([
            'id' => 'GONE-01',
            'expiry_date' => $this->inDays(-1),
            'status' => BloodUnitStatus::Discarded,
            'discard_reason' => 'Seal broken during handling',
            'discarded_at' => OperationalDay::today(),
        ]);
        $this->makeUnit(['id' => 'FIXED-01', 'expiry_date' => $this->inDays(30)]);
        $this->makeUnit([
            'id' => 'EXP-01',
            'expiry_date' => $this->inDays(-4),
            'status' => BloodUnitStatus::Expired,
            'expired_at' => OperationalDay::today()->subDays(2),
        ]);
    }

    /**
     * @param  array<int, string>  $ids
     */
    private function seedDueUnits(array $ids): void
    {
        foreach ($ids as $id) {
            $this->makeUnit(['id' => $id, 'expiry_date' => $this->inDays(-1)]);
        }
    }

    private function hasExpiredAuditRow(string $unitId): bool
    {
        return AuditLog::query()
            ->where('action', 'inventory.expired')
            ->where('auditable_id', $unitId)
            ->exists();
    }

    private function sweptRow(): AuditLog
    {
        return AuditLog::query()->where('action', 'inventory.expiry_swept')->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeUnit(array $overrides = [], ?int $facilityId = null): BloodUnit
    {
        $facilityId ??= $this->facilityId;

        $donation = Donation::factory()->create([
            'facility_id' => $facilityId,
            'donor_id' => $this->donorProfile->donor_id,
        ]);

        return BloodUnit::factory()->create([
            'facility_id' => $facilityId,
            'blood_type_id' => $this->bloodType->id,
            'component_id' => $this->component->id,
            'donation_id' => $donation->id,
            ...$overrides,
        ]);
    }

    private function unit(string $id): BloodUnit
    {
        return BloodUnit::query()->findOrFail($id);
    }

    private function inDays(int $days): string
    {
        return OperationalDay::today()->addDays($days)->toDateString();
    }
}
