<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DonorProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'donor_id';

    public $incrementing = false;

    protected $fillable = [
        'donor_id',
        'blood_type_id',
        'gender',
        'birth_date',
        'address',
        'last_donation_date',
        'valid_id_number',
        'profile_image_path',
        'notification_preferences',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'last_donation_date' => 'date',
            'notification_preferences' => 'array',
        ];
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donor_id');
    }

    public function bloodType(): BelongsTo
    {
        return $this->belongsTo(BloodType::class);
    }
}
