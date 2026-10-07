<?php

namespace App\Repository;

use App\Models\BloodUnit;
use App\Models\DirectDistribution;
use App\Models\ExternalBloodSource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every read and write of the bags a hospital received from outside RedAgos.
 *
 * Receipts are scoped to the receiving hospital throughout. The source list
 * is shared by every blood bank, and bag keys are global.
 */
class DirectDistributionRepository
{
    /**
     * What a receipt shows beside its bag.
     *
     * @var array<int, string>
     */
    private const RELATIONS = [
        'source:id,name,code',
        'receiver:id,first_name,last_name',
        'transfusionRequest:id,reference_number',
        'bloodUnits:id,direct_distribution_id,blood_type_id,component_id,volume_ml,expiry_date',
        'bloodUnits.bloodType:id,code',
        'bloodUnits.component:id,name',
        'bloodUnits.hospitalUnit:id,unit_id,status',
    ];

    /**
     * Whether a source already holds a bag under this number.
     */
    public function receiptExists(int $sourceId, string $externalUnitNumber): bool
    {
        return DirectDistribution::query()
            ->where('external_blood_source_id', $sourceId)
            ->where('external_unit_number', $externalUnitNumber)
            ->exists();
    }

    /**
     * Which of these blood unit keys are already taken.
     *
     * A fresh receipt's DD- keys cannot collide with another receipt's, but a
     * RedAgos bag number could in principle read the same; this is the check.
     *
     * @param  array<int, string>  $unitIds
     * @return array<int, string>
     */
    public function takenUnitIds(array $unitIds): array
    {
        return BloodUnit::query()->whereIn('id', $unitIds)->pluck('id')->all();
    }

    /**
     * Whether a RedAgos bag already carries this number as its id.
     */
    public function redagosUnitExists(string $unitId): bool
    {
        return BloodUnit::query()->whereKey($unitId)->whereNull('direct_distribution_id')->exists();
    }

    /**
     * Book the bag of a receipt.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createUnit(array $attributes): BloodUnit
    {
        return BloodUnit::query()->create($attributes);
    }

    /**
     * List a hospital's receipts, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DirectDistribution>
     */
    public function paginateFor(int $facilityId, array $filters, int $perPage): LengthAwarePaginator
    {
        return DirectDistribution::query()
            ->forFacility($facilityId)
            ->when(
                isset($filters['transfusion_request_id']),
                fn (Builder $query): Builder => $query->where('transfusion_request_id', $filters['transfusion_request_id'])
            )
            ->when(
                isset($filters['search']),
                fn (Builder $query): Builder => $query->where(fn (Builder $search): Builder => $search
                    ->where('external_unit_number', 'like', '%'.$filters['search'].'%')
                    ->orWhere('requested_for', 'like', '%'.$filters['search'].'%')
                    ->orWhereHas('source', fn (Builder $source): Builder => $source->where('name', 'like', '%'.$filters['search'].'%')))
            )
            ->with(self::RELATIONS)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Find one receipt of a hospital, with its bag.
     */
    public function findFor(int $directDistributionId, int $facilityId): ?DirectDistribution
    {
        return DirectDistribution::query()
            ->forFacility($facilityId)
            ->whereKey($directDistributionId)
            ->with(self::RELATIONS)
            ->first();
    }

    /**
     * Every external blood source, by name.
     *
     * @return Collection<int, ExternalBloodSource>
     */
    public function sources(): Collection
    {
        return ExternalBloodSource::query()->orderBy('name')->get();
    }

    /**
     * Find a source by name, ignoring case and surrounding space.
     */
    public function findSourceByName(string $name): ?ExternalBloodSource
    {
        return ExternalBloodSource::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->first();
    }

    /**
     * Add a source to the shared list.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createSource(array $attributes): ExternalBloodSource
    {
        return ExternalBloodSource::query()->create($attributes);
    }
}
