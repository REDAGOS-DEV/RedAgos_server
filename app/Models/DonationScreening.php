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

        // Section I-D. Asked in person or observed by the screening officer at
        // the counter; never written from the donor's side of the app.
        'sleep',
        'meal',
        'meds',
        'allergies',
        'general_appearance',
        'skin',
        'heent',
        'heart_and_lungs',
        'systolic_bp',
        'diastolic_bp',
        'pulse_bpm',
        'temperature_c',
        'weight_kg',
        'haemoglobin_g_dl',

        // Section II's fingerprick table. Preliminary: never copied onto the
        // donor profile and never used to pre-fill the laboratory's typing.
        'fingerprick_blood_type_id',
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
     * The blood type read off the fingerprick slide at the screening table.
     */
    public function fingerprickBloodType(): BelongsTo
    {
        return $this->belongsTo(BloodType::class, 'fingerprick_blood_type_id');
    }

    /**
     * The staff member who entered the record.
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
