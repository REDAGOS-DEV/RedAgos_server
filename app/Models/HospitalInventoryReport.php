<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One generated Inventory Report, kept as the PDF it was printed as.
 */
class HospitalInventoryReport extends Model
{
    protected $fillable = [
        'facility_id',
        'generated_by',
        'layout',
        'components',
        'blood_types',
        'totals',
        'pages',
        'file_path',
        'file_size',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'blood_types' => 'array',
            'totals' => 'array',
            'pages' => 'integer',
            'file_size' => 'integer',
        ];
    }

    /**
     * The blood bank whose shelf it covers.
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * Who generated it.
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * Limit the query to one blood bank's reports.
     */
    public function scopeForFacility(Builder $query, int $facilityId): Builder
    {
        return $query->where('facility_id', $facilityId);
    }
}
