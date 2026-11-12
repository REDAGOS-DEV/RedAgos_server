<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One partner organisation's batch of people, sent through a drive's
 * registration link. The organisation and its contact are as typed: partners
 * hold no accounts.
 */
class DrivePartnerSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'mobile_event_id',
        'organisation_name',
        'contact_name',
        'contact_phone',
        'contact_email',
        'consent_at',
        'anonymised_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
            'anonymised_at' => 'datetime',
        ];
    }

    public function drive(): BelongsTo
    {
        return $this->belongsTo(MobileEvent::class, 'mobile_event_id');
    }

    public function preRegistrations(): HasMany
    {
        return $this->hasMany(DrivePreRegistration::class, 'partner_submission_id');
    }
}
