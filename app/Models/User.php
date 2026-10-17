<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\Department;
use App\Enums\HospitalRole;
use App\Enums\ManagementLevel;
use App\Enums\RoleName;
use App\Enums\StaffRole;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\AdminPrivileges;
use App\Support\DepartmentPermissions;
use App\Support\HospitalPermissions;
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
    'middle_name',
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
    'is_super_admin',
    'admin_privileges',
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
            'staff_role' => StaffRole::class,
            'additional_staff_roles' => 'array',
            'hospital_role' => HospitalRole::class,
            'staff_privileges' => 'array',
            'management_level' => ManagementLevel::class,
            'is_super_admin' => 'boolean',
            'admin_privileges' => 'array',
            'password' => 'hashed',
        ];
    }

    /**
     * Derive the department from the staff role on every save.
     *
     * A predefined role decides the department, and any department assigned
     * alongside it is overwritten on save so the two can never disagree. A
     * custom role keeps the department it was assigned, since nothing else
     * could supply one. An account with no role at all has no department.
     *
     * The additional roles are kept to the main role's department, without
     * repeats and without the main role itself: one person's roles are all in
     * one department (project owner, 2026-10-17). A list with no main role
     * promotes its first entry, so an account never holds only "extra" roles.
     * StaffService refuses a mismatch with a message first; this is the floor.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $additional = array_values(array_filter(array_map(
                fn ($value): ?StaffRole => StaffRole::tryFrom((string) $value),
                $user->additional_staff_roles ?? []
            )));

            if ($user->staff_role === null && $additional !== []) {
                $user->staff_role = array_shift($additional);
            }

            if ($user->staff_role !== null) {
                $user->department = $user->staff_role->department();
            } elseif (blank($user->custom_role)) {
                $user->department = null;
            }

            $kept = [];

            foreach ($additional as $role) {
                if ($role !== $user->staff_role && $role->department() === $user->department && ! in_array($role->value, $kept, true)) {
                    $kept[] = $role->value;
                }
            }

            $user->additional_staff_roles = $kept === [] ? null : $kept;
        });
    }

    /**
     * Every predefined role the account holds: the main one first.
     *
     * @return array<int, StaffRole>
     */
    public function staffRoles(): array
    {
        $roles = $this->staff_role !== null ? [$this->staff_role] : [];

        foreach ($this->additional_staff_roles ?? [] as $value) {
            $role = StaffRole::tryFrom((string) $value);

            if ($role !== null && ! in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * Whether the account holds this predefined role, as its main role or another.
     *
     * Use this, never `staff_role ===`, wherever a role is a qualification:
     * the department's correction approver, the Billing Supervisor.
     */
    public function hasStaffRole(StaffRole $role): bool
    {
        return in_array($role, $this->staffRoles(), true);
    }

    /**
     * Every role as the staff roster shows it: the predefined titles, then the custom one typed.
     *
     * @return array<int, string>
     */
    public function roleLabels(): array
    {
        return [
            ...array_map(fn (StaffRole $role): string => $role->label(), $this->staffRoles()),
            ...(filled($this->custom_role) ? [$this->custom_role] : []),
        ];
    }

    /**
     * The roles on one line, e.g. "Medical Receptionist, Phlebotomist".
     */
    public function roleLabel(): ?string
    {
        $labels = $this->roleLabels();

        return $labels === [] ? null : implode(', ', $labels);
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
     * Get every ability this account holds through its staff role or its management level.
     *
     * This is what the `can:` route middleware resolves against, and what the
     * client mirrors to decide which navigation to render. The server remains
     * the only real gate; the client copy is presentation.
     *
     * @return array<int, string>
     */
    public function abilities(): array
    {
        return array_values(array_unique([
            ...DepartmentPermissions::for($this),
            ...HospitalPermissions::for($this),
            ...AdminPrivileges::for($this),
        ]));
    }

    /**
     * Whether this account is its blood centre's Admin: staff, roles, settings
     * and escalated correction decisions, but no department work.
     */
    public function isCenterAdmin(): bool
    {
        return $this->management_level === ManagementLevel::Admin;
    }

    /**
     * Whether this account holds the view-only Supervisor level.
     *
     * Not the Laboratory or Billing Supervisor: those are staff roles.
     */
    public function isCenterSupervisor(): bool
    {
        return $this->management_level === ManagementLevel::Supervisor;
    }

    /**
     * Determine whether the user works in the given department.
     *
     * Deliberately independent of management_level: this answers "where do
     * they work", not "what may they do". Use abilities() for the latter,
     * because a level adds abilities whatever their department says.
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
        // A donor registered at the counter may hold no address. Notifying them
        // would build a signed URL around a null and fail at the mailer, so the
        // send is skipped rather than guarded at every call site.
        if ($this->email === null) {
            return;
        }

        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Determine whether this account can be reached by any email-based flow.
     *
     * Verification, password reset and login all match on a supplied address,
     * and `WHERE email = ?` never matches NULL, so an email-less donor is
     * unreachable by all three. This makes that explicit where it matters.
     */
    public function hasEmailAddress(): bool
    {
        return $this->email !== null;
    }

    /**
     * Send the SPA-addressed password reset notification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
