<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\Department;
use App\Enums\RoleName;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\DepartmentPermissions;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'uuid',
    'first_name',
    'last_name',
    'email',
    'phone',
    'username',
    'password',
    'account_status',
    'activated_at',
    'terms_accepted_at',
    'employee_id',
    'position',
])]

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'account_status' => AccountStatus::PendingVerification->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'account_status' => AccountStatus::class,
            'department' => Department::class,
            'is_supervisor' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function donorProfile(): HasOne
    {
        return $this->hasOne(DonorProfile::class, 'donor_id');
    }

    /**
     * The facility this staff account belongs to.
     *
     * Null for donors and administrators. Every facility-scoped query resolves
     * through this rather than through request input, so there is no IDOR
     * surface.
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * Determine whether the user holds the given role.
     */
    public function hasRole(RoleName $role): bool
    {
        return $this->roles->contains('name', $role->value);
    }

    /**
     * Get every ability this account holds through its department or the management level.
     *
     * This is what the `can:` route middleware resolves against, and what the
     * client mirrors to decide which navigation to render. The server remains
     * the only real gate; the client copy is presentation.
     *
     * @return array<int, string>
     */
    public function abilities(): array
    {
        return DepartmentPermissions::for($this);
    }

    /**
     * Determine whether the user works in the given department.
     *
     * Deliberately independent of is_supervisor: this answers "where do they
     * work", not "what may they do". Use abilities() for the latter, because a
     * supervisor holds every ability whatever their department says.
     */
    public function worksIn(Department $department): bool
    {
        return $this->department === $department;
    }

    /**
     * Send the SPA-addressed email verification notification.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Send the SPA-addressed password reset notification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
