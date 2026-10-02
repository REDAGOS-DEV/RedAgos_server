<?php

namespace App\Models;

use App\Enums\EligibilityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EligibilityScreening extends Model
{
    use HasFactory;

    protected $fillable = [
        'donor_id',
        'question_version',
        'screened_at',
        'valid_until',
        'result',
        'computed_result',
        'submitted_result',
        'age_at_screening',
        'gender_at_screening',
        'weight_kg',
        'declared_last_donation_date',
        'declared_last_donation_venue',
        'last_menstrual_period',
        'deferral_reasons',
        'consented_at',
        'consent_version',
        'consent_text_hash',
    ];

    protected function casts(): array
    {
        return [
            'screened_at' => 'datetime',
            'valid_until' => 'datetime',
            'declared_last_donation_date' => 'date',
            'last_menstrual_period' => 'date',
            'consented_at' => 'datetime',
            'result' => EligibilityStatus::class,
            'question_version' => 'integer',
            'age_at_screening' => 'integer',
            'weight_kg' => 'integer',
            'deferral_reasons' => 'array',
        ];
    }

    /**
     * Determine whether the donor accepted the informed consent statements.
     *
     * Screenings recorded before Section I-C was captured have no consent, and
     * that gap must always surface as an explicit negative. Rendering a missing
     * consent as a blank date is the worst failure this record can produce.
     */
    public function hasConsent(): bool
    {
        return $this->consented_at !== null;
    }

    public function donorProfile(): BelongsTo
    {
        return $this->belongsTo(DonorProfile::class, 'donor_id', 'donor_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(EligibilityScreeningAnswer::class, 'screening_id');
    }

    public function qrTokens(): HasMany
    {
        return $this->hasMany(DonorQrToken::class, 'screening_id');
    }

    /**
     * Determine whether this screening still stands as of now.
     *
     * "Valid" means answered and unexpired, and deliberately says nothing about
     * the outcome. RedAgos no longer scores a donor's own answers into a
     * verdict: the questionnaire is a record of what was asked and answered,
     * and whether the donor may give blood is decided by the blood centre at
     * the counter, from their own assessment.
     *
     * This used to require result = eligible. That filter had to go along with
     * the verdict -- every screening is now recorded `pending`, so leaving it
     * would make every new screening invisible here, which silently breaks the
     * QR refresh and the re-screen guard without raising anything.
     */
    public function isValid(): bool
    {
        return $this->valid_until->isFuture();
    }

    /**
     * Limit the query to answered screenings that have not yet lapsed.
     */
    public function scopeCurrentlyValid(Builder $query): Builder
    {
        return $query->where('valid_until', '>', now());
    }
}
