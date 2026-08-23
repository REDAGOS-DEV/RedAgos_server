<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MobileEvent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'facility_id',
        'created_by',
        'name',
        'location',
        'event_date',
        'max_capacity',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'max_capacity' => 'integer',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(DonationAppointment::class, 'event_id');
    }
}
