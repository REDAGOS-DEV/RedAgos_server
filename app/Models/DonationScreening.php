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
        // The screening physician as a drive's paper record names them. Null
        // at the counter, where the physician records it themselves.
        'screened_by_name',
        'outcome',
        // The centre's deferral code, and the reason it stood for when the
        // screening was recorded (DeferralCodeService). Null on rows recorded
        // before codes existed, which have only the typed reason.
        'deferral_code',
        'deferral_reason',
        // A temporary deferral's last day, set at the counter; null otherwise.
        'deferred_until',
        // The deferral lifted from the Deferrals page; the outcome stays.
        'lifted_at',
        'lifted_by',
        'lift_reason',

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
        // The donor answered the printed DHQ instead of the onsite one.
        'dhq_on_paper',
        'screened_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => ScreeningOutcome::class,
            'deferred_until' => 'date',
            'lifted_at' => 'datetime',
            'screened_at' => 'datetime',
            'systolic_bp' => 'integer',
            'diastolic_bp' => 'integer',
            'pulse_bpm' => 'integer',
            'weight_kg' => 'integer',
            'temperature_c' => 'float',
            'haemoglobin_g_dl' => 'float',
            'dhq_on_paper' => 'boolean',
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

    public function lifter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }

    /**
     * Who screened the donor: the name on a paper record, otherwise the staff
     * member who recorded it at the counter, which is the same person there.
     */
    public function screenerName(): ?string
    {
        if (filled($this->screened_by_name)) {
            return $this->screened_by_name;
        }

        return $this->recorder ? $this->recorder->signatureName() : null;
    }
}
