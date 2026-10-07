<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The receipt of blood from outside RedAgos, under the identifier its sender gave it.
 *
 * For a Patient Transfusion Request it is one bag, scanned or typed. Without
 * one, it is typed by hand: one identifier for `quantity` bags, and who asked
 * for them. Either way the identifier is kept as given; RedAgos never replaces
 * it with a barcode of its own.
 *
 * Each bag is a blood_units row keyed internally — DD-{id}, then DD-{id}-2,
 * DD-{id}-3, as BagNumbers numbers the second bag of a component — which staff
 * never see. A bag received for a requirement is linked to it but does not
 * change its figures, which count what centres supply.
 */
class DirectDistribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'facility_id',
        'transfusion_request_id',
        'requested_for',
        'external_blood_source_id',
        'external_unit_number',
        'quantity',
        'collection_date',
        'received_at',
        'received_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'collection_date' => 'immutable_date',
            'received_at' => 'immutable_datetime',
        ];
    }

    /**
     * The internal blood_units key for one bag of a receipt.
     *
     * The first bag is DD-{id}; the second and later carry -2, -3, the way
     * BagNumbers numbers a second bag of one component.
     */
    public static function unitKey(int $id, int $sequence = 1): string
    {
        return $sequence <= 1 ? 'DD-'.$id : "DD-{$id}-{$sequence}";
    }

    /**
     * Which bag of its receipt a blood unit key names, counting from one.
     */
    public static function sequenceOf(string $unitKey): int
    {
        return preg_match('/^DD-\d+-(\d+)$/', $unitKey, $match) === 1 ? (int) $match[1] : 1;
    }

    /**
     * The number staff see for one bag: the sender's identifier, with its place in a batch of several.
     */
    public function bagNumberFor(string $unitKey): string
    {
        return $this->quantity > 1
            ? $this->external_unit_number.' #'.self::sequenceOf($unitKey)
            : $this->external_unit_number;
    }

    /**
     * The hospital blood bank that received the blood.
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * The patient's requirement the bag was received for, if any.
     */
    public function transfusionRequest(): BelongsTo
    {
        return $this->belongsTo(TransfusionRequest::class);
    }

    /**
     * The blood service that sent it.
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ExternalBloodSource::class, 'external_blood_source_id');
    }

    /**
     * The staff member who received it.
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * The bags received. Order them with sequenceOf(): as strings, DD-5-10 sorts before DD-5-2.
     */
    public function bloodUnits(): HasMany
    {
        return $this->hasMany(BloodUnit::class);
    }

    /**
     * Limit the query to one hospital's receipts.
     */
    public function scopeForFacility(Builder $query, int $facilityId): Builder
    {
        return $query->where('facility_id', $facilityId);
    }
}
