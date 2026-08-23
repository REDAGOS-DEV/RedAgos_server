<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Donation extends Model
{
    use HasFactory;

    protected $fillable = [
        'donor_id',
        'facility_id',
        'appointment_id',
        'donation_date',
        'status',
        'volume_ml',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'registered',
    ];

    protected function casts(): array
    {
        return [
            'donation_date' => 'datetime',
            'volume_ml' => 'integer',
        ];
    }

    public function donorProfile(): BelongsTo
    {
        return $this->belongsTo(DonorProfile::class, 'donor_id', 'donor_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(DonationAppointment::class, 'appointment_id');
    }

    /**
     * Limit the query to donations that reached collection.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }
}
