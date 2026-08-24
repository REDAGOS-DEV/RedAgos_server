<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BloodComponent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'price',
        'shelf_life_days',
        'storage_temperature',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'shelf_life_days' => 'integer',
        ];
    }

    /**
     * Determine whether this component has a clinically approved shelf life.
     *
     * Expiry derivation must refuse to run without one rather than fall back to
     * a default, so callers check this instead of coalescing a null away.
     */
    public function hasShelfLife(): bool
    {
        return $this->shelf_life_days !== null;
    }
}
