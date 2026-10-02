<?php

namespace App\Console\Commands;

use App\Enums\HospitalUnitStatus;
use App\Models\BloodUnit;
use App\Repository\HospitalInventoryRepository;
use App\Service\AuditLogger;
use App\Support\OperationalDay;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Move past-expiry available bags in hospital blood banks to expired.
 *
 * The hospital's counterpart to inventory:expire-units, and deliberately a
 * separate command: that sweep reads blood_units, where a received bag still
 * says `issued` at its centre, and must stay exactly as it is. This one reads
 * hospital_units and never writes blood_units.
 *
 * Touches `available` only. A tagged bag past its date is refused at
 * crossmatch and transfusion, and comes back to `available` — and so to this
 * sweep — once its tag ends.
 */
class ExpireHospitalUnits extends Command
{
    /**
     * How many candidates each locked transaction handles.
     */
    private const CHUNK = 500;

    protected $signature = 'hospital:expire-units';

    protected $description = 'Expire hospital blood bank units whose expiry date has passed';

    public function __construct(
        private readonly HospitalInventoryRepository $repository,
        private readonly AuditLogger $auditLogger
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = OperationalDay::todayAsDate();
        $sweptAt = now()->startOfSecond()->toImmutable();
        $runId = (string) Str::uuid();

        $expired = 0;
        $countsByFacility = [];

        $this->repository->dueUnits($today)->chunkById(
            self::CHUNK,
            function (Collection $candidates) use ($today, $sweptAt, $runId, &$expired, &$countsByFacility): void {
                DB::transaction(function () use ($candidates, $today, $sweptAt, $runId, &$expired, &$countsByFacility): void {
                    $confirmed = $this->repository->lockConfirmedDueUnits($candidates->pluck('id')->all(), $today);

                    if ($confirmed->isEmpty()) {
                        return;
                    }

                    $affected = $this->repository->markExpired($confirmed->pluck('id')->all(), $today, $sweptAt);

                    if ($affected !== $confirmed->count()) {
                        throw new RuntimeException(
                            "hospital expiry sweep {$runId}: updated {$affected} of {$confirmed->count()}"
                        );
                    }

                    $bags = BloodUnit::query()->whereIn('id', $confirmed->pluck('unit_id'))->get()->keyBy('id');

                    foreach ($confirmed as $unit) {
                        $this->auditLogger->record(null, 'hospital_inventory.expired', $bags->get($unit->unit_id), [
                            'facility_id' => $unit->facility_id,
                            'hospital_unit_id' => $unit->id,
                            'operational_date' => $today,
                            'expiry_date' => $unit->bloodUnit?->expiry_date?->toDateString(),
                            'previous_status' => HospitalUnitStatus::Available->value,
                            'new_status' => HospitalUnitStatus::Expired->value,
                            'source' => 'schedule:hospital:expire-units',
                            'run_id' => $runId,
                        ]);

                        $countsByFacility[$unit->facility_id] = ($countsByFacility[$unit->facility_id] ?? 0) + 1;
                    }

                    $expired += $confirmed->count();
                });
            },
            'hospital_units.id',
            'id'
        );

        // Written on every run, including ones that expire nothing — and it is
        // also the hospital scheduler's daily heartbeat, since the per-minute
        // tag sweep only writes a run row when it moves something.
        $this->auditLogger->record(null, 'hospital_inventory.expiry_swept', null, [
            'run_id' => $runId,
            'operational_date' => $today,
            'expired_count' => $expired,
            'by_facility' => $countsByFacility,
            'source' => 'schedule:hospital:expire-units',
        ]);

        $this->info("Expired {$expired} hospital blood unit(s) as of {$today}.");

        return self::SUCCESS;
    }
}
