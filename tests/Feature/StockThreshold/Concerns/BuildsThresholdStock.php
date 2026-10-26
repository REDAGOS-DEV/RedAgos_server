<?php

namespace Tests\Feature\StockThreshold\Concerns;

use App\Enums\BloodUnitStatus;
use App\Enums\HospitalUnitStatus;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\HospitalUnit;
use App\Models\User;

/**
 * Shelves of known size, for tests that judge stock against a minimum.
 *
 * Units are written the way the other inventory tests write them, one donation
 * each, so every bag traces to a donation as the schema requires.
 */
trait BuildsThresholdStock
{
    private ?DonorProfile $thresholdDonor = null;

    private ?Facility $thresholdCentre = null;

    /**
     * Get a blood type by code, creating it on first use.
     *
     * firstOrCreate, because DonorProfileFactory and BloodTypeFactory draw
     * from the same eight codes and the code column is unique.
     */
    protected function bloodType(string $code): BloodType
    {
        return BloodType::firstOrCreate(['code' => $code], ['label' => $code]);
    }

    /**
     * Put bags on a blood centre's shelf.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<int, BloodUnit>
     */
    protected function stockCentre(Facility $centre, BloodType $type, BloodComponent $component, int $count, array $overrides = []): array
    {
        $this->thresholdDonor ??= DonorProfile::factory()->create([
            'donor_id' => User::factory()->create()->id,
            'blood_type_id' => $type->id,
        ]);

        $units = [];

        for ($i = 0; $i < $count; $i++) {
            $donation = Donation::factory()->create([
                'facility_id' => $centre->id,
                'donor_id' => $this->thresholdDonor->donor_id,
            ]);

            $units[] = BloodUnit::factory()->create([
                'facility_id' => $centre->id,
                'blood_type_id' => $type->id,
                'component_id' => $component->id,
                'donation_id' => $donation->id,
                ...$overrides,
            ]);
        }

        return $units;
    }

    /**
     * Put bags in a hospital blood bank's custody.
     *
     * The bag stays `issued` at a centre, as it does after a real receipt; only
     * the hospital's own row carries the status under test.
     *
     * @param  array<string, mixed>  $unitOverrides
     * @return array<int, HospitalUnit>
     */
    protected function stockHospital(
        Facility $hospital,
        BloodType $type,
        BloodComponent $component,
        int $count,
        HospitalUnitStatus $status = HospitalUnitStatus::Available,
        array $unitOverrides = []
    ): array {
        $this->thresholdCentre ??= Facility::factory()->approved()->create();

        $bags = $this->stockCentre($this->thresholdCentre, $type, $component, $count, [
            'status' => BloodUnitStatus::Issued,
            ...$unitOverrides,
        ]);

        return array_map(fn (BloodUnit $bag): HospitalUnit => HospitalUnit::create([
            'facility_id' => $hospital->id,
            'unit_id' => $bag->id,
            'request_allocation_id' => null,
            'status' => $status,
        ]), $bags);
    }
}
