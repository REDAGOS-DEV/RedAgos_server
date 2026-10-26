<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stack of a drive's paper records, typed in after the drive.
 *
 * A remote drive screens and draws on paper. When the sheets and bags come
 * back, staff open a sheet for that drive and day and type each donor in; the
 * donations it produces are ordinary donations dated on the drive's day, and
 * point back here so the list can say where they came from.
 */
class PaperDonationSheet extends Model
{
    protected $fillable = [
        'facility_id',
        'mobile_event_id',
        'drive_name',
        'location',
        'held_on',
        'starts_at',
        'screened_by_name',
        'phlebotomist_name',
        'notify_donors',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'held_on' => 'date',
            // Wall-clock time with no date of its own, as MobileEvent keeps its
            // start_time; a datetime cast would put it on today's date.
            'starts_at' => 'string',
            'notify_donors' => 'boolean',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * The drive, when it was planned in RedAgos.
     */
    public function mobileEvent(): BelongsTo
    {
        return $this->belongsTo(MobileEvent::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'paper_sheet_id');
    }

    /**
     * The sheet's reference, PS-000012.
     */
    public function code(): string
    {
        return 'PS-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * The opening time as HH:MM, however the database hands it back.
     */
    public function startTime(): string
    {
        return substr((string) $this->starts_at, 0, 5);
    }
}
