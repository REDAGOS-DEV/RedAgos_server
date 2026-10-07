<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A blood service outside RedAgos that a hospital receives bags from.
 *
 * Together with the number the service printed on a bag, it identifies the
 * bag: two services may print the same number.
 */
class ExternalBloodSource extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'created_by'];

    /**
     * The receipts of bags from this source.
     */
    public function directDistributions(): HasMany
    {
        return $this->hasMany(DirectDistribution::class);
    }
}
