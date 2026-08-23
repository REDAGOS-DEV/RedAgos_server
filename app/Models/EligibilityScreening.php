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
        'weight_kg',
        'declared_last_donation_date',
        'deferral_reasons',
    ];

    protected function casts(): array
    {
        return [
            'screened_at' => 'datetime',
            'valid_until' => 'datetime',
            'declared_last_donation_date' => 'date',
            'result' => EligibilityStatus::class,
            'question_version' => 'integer',
            'age_at_screening' => 'integer',
            'weight_kg' => 'integer',
            'deferral_reasons' => 'array',
        ];
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
     */
    public function isValid(): bool
    {
        return $this->result === EligibilityStatus::Eligible
            && $this->valid_until->isFuture();
    }

    /**
     * Limit the query to eligible screenings that have not yet lapsed.
     */
    public function scopeCurrentlyValid(Builder $query): Builder
    {
        return $query->where('result', EligibilityStatus::Eligible->value)
            ->where('valid_until', '>', now());
    }
}
