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
     * Limit the query to donations cleared for issue.
     *
     * `completed` means testing is finished and the blood may reach a patient —
     * `tested` is the separate, earlier status. The two are not the same, and
     * this docblock previously said "reached collection" while the query
     * filtered on `completed`. Blood-unit intake gates on this distinction; see
     * the donation-status entry in docs/IMPLEMENTATION_DECISIONS.md.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }
}
