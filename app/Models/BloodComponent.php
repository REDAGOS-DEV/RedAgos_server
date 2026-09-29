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
        'code',
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

    /**
     * The suffix this component adds to a bag number: PRBC, FFP, …
     *
     * The stored code, or — for a component added without one — the initials
     * of its name, so a bag can always be numbered. Letters and digits only,
     * because a bag number is a unit id.
     */
    public function labelCode(): string
    {
        $code = $this->code ?: collect(preg_split('/[\s\-]+/', (string) $this->name))
            ->filter()
            ->map(fn (string $word): string => mb_substr($word, 0, 1))
            ->implode('');

        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)) ?: 'X';
    }
}
