<?php

namespace App\Models;

use App\Enums\DonorEmailTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A blood centre's own wording for one donor email. See the migration.
 */
class FacilityEmailTemplate extends Model
{
    protected $fillable = [
        'facility_id',
        'template',
        'subject',
        'body',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'template' => DonorEmailTemplate::class,
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
