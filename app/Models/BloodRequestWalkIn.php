<?php

namespace App\Models;

use App\Enums\ValidIdType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The part of a walk-in request that a portal request never has.
 *
 * The watcher is the representative who presented the request, not the
 * requester: the hospital blood bank stays the institutional party. The
 * verifier is the hospital staff member who confirmed the request by phone
 * before it was created — a walk-in is never entered on anything less.
 *
 * None of these values is written to audit_logs, which carries identifiers
 * only.
 */
class BloodRequestWalkIn extends Model
{
    protected $table = 'blood_request_walk_ins';

    protected $fillable = [
        'request_id',
        'representative_name',
        'representative_relationship',
        'representative_contact',
        'representative_id_type',
        'representative_id_number',
        'presented_reference',
        'attending_physician',
        'patient_ward',
        'patient_record_number',
        'verifier_name',
        'verifier_position',
        'verifier_contact',
        'verified_at',
        'verification_recorded_by',
        'verification_notes',
        'duplicate_acknowledgement',
    ];

    protected function casts(): array
    {
        return [
            'representative_id_type' => ValidIdType::class,
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'request_id');
    }

    /**
     * The blood-centre staff member who took the hospital's confirmation.
     */
    public function verificationRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verification_recorded_by');
    }
}
