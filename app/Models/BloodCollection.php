<?php

namespace App\Models;

use App\Enums\BloodBagType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of a physical collection: which staff member drew which donation, and when.
 *
 * One row per donation — `blood_collections.donation_id` is unique — so this is
 * the traceability link the Capstone data dictionary asks for between a bag and
 * the person who drew it. It is also the "For Phlebotomist Use Only" box of
 * Section II of the DOH form: the bag, the donation barcode and the draw times.
 */
class BloodCollection extends Model
{
    protected $fillable = [
        'donation_id',
        // Copied from the donation so the donation barcode can be unique per
        // facility; a unique index cannot reach through `donations`.
        'facility_id',
        'collected_by',
        // The phlebotomist as a drive's paper record names them; collected_by
        // is then the staff member who typed it. Null at the counter.
        'phlebotomist_name',
        'collection_datetime',
        'blood_bag_type',
        'donation_barcode',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'collection_datetime' => 'datetime',
            'blood_bag_type' => BloodBagType::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
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
     * The staff member who performed the collection — the form's "Phlebotomist".
     */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    /**
     * Who drew the bag: the name on a paper record, otherwise the staff member
     * who recorded the collection at the chair.
     */
    public function phlebotomistName(): ?string
    {
        if (filled($this->phlebotomist_name)) {
            return $this->phlebotomist_name;
        }

        return $this->collector ? $this->collector->signatureName() : null;
    }
}
