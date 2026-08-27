<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of a physical collection: which staff member drew which donation, and when.
 *
 * One row per donation — `blood_collections.donation_id` is unique — so this is
 * the traceability link the Capstone data dictionary asks for between a bag and
 * the person who drew it.
 */
class BloodCollection extends Model
{
    protected $fillable = [
        'donation_id',
        'collected_by',
        'collection_datetime',
    ];

    protected function casts(): array
    {
        return [
            'collection_datetime' => 'datetime',
        ];
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /**
     * The staff member who performed the collection.
     */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
