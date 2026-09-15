<?php

namespace App\Models;

use App\Enums\ScreeningOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The on-site screening and pre-donation assessment recorded against a donation.
 *
 * RedAgos does not screen or examine anyone. A qualified professional does, and
 * this is the record of what they reported — see the scope boundary in
 * docs/BLOOD-CENTER.md. Nothing here computes or infers an outcome from the
 * vitals.
 */
class DonationScreening extends Model
{
    use HasFactory;

    protected $fillable = [
        'donation_id',
        'facility_id',
        'recorded_by',
        'outcome',
        'deferral_reason',
        'systolic_bp',
        'diastolic_bp',
        'pulse_bpm',
        'temperature_c',
        'weight_kg',
        'haemoglobin_g_dl',
        'notes',
        'screened_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => ScreeningOutcome::class,
            'screened_at' => 'datetime',
            'systolic_bp' => 'integer',
            'diastolic_bp' => 'integer',
            'pulse_bpm' => 'integer',
            'weight_kg' => 'integer',
            'temperature_c' => 'float',
            'haemoglobin_g_dl' => 'float',
        ];
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * The staff member who entered the record.
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
