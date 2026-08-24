<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/* Spatie */
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory;
    use Notifiable;
    use HasRoles {
        hasPermissionTo as protected spatieHasPermissionTo;
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(TeacherSalary::class, 'employee_user_id');
    }

    public function salaryRates(): HasMany
    {
        return $this->hasMany(EmployeeSalaryRate::class, 'employee_user_id');
    }

    // Phase 3: cash-drawer sessions this user opened.
    public function cashSessions(): HasMany
    {
        return $this->hasMany(CashSession::class, 'opened_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers (اختياري)
    |--------------------------------------------------------------------------
    */

    /**
     * Batch 6: closes the previously-open gap where any authenticated
     * user, regardless of role, could reach both panels. Deny by default —
     * only the listed roles for each panel are admitted. The Teacher
     * Portal additionally requires an active, uniquely linked teacher.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->canAccessAdministrativePortal(),
            'teacher' => $this->hasRole('teacher')
                && ! $this->hasAnyRole(array_diff(self::administrativeRoles(), ['super-admin']))
                && $this->teacher()->where('is_active', true)->exists(),
            default => false,
        };
    }

    public function canAccessAdministrativePortal(): bool
    {
        return $this->isActive() && $this->hasAnyRole($this->administrativeRoles());
    }

    public function isActive(): bool
    {
        if (array_key_exists('is_active', $this->getAttributes())) {
            return $this->is_active === true;
        }

        return $this->newQuery()->whereKey($this->getKey())->value('is_active') === true;
    }

    /**
     * @return array<int, string>
     */
    public static function administrativeRoles(): array
    {
        return ['super-admin', 'admin', 'school-admin', 'accountant', 'cashier', 'reception', 'principal'];
    }

    /**
     * @return array<int, string>
     */
    public static function protectedPolicyAbilities(): array
    {
        return [
            'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
            'restore', 'restoreAny', 'forceDelete', 'forceDeleteAny',
        ];
    }

    /**
     * The protected super-admin role is a true, automatic bypass of
     * every permission, not just whatever the seeder currently lists. Used
     * by the hasPermissionTo() override below and by Gate::before in
     * AuthServiceProvider.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    public function isPrincipal(): bool
    {
        return $this->hasRole('principal');
    }

    public function isLastActiveSuperAdmin(): bool
    {
        if (! $this->isActive() || ! $this->isSuperAdmin()) {
            return false;
        }

        return static::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', 'super-admin'))
            ->count() === 1;
    }

    /**
     * Overrides Spatie's HasPermissions::hasPermissionTo(). hasAnyPermission()/
     * hasAllPermissions() both funnel through this via checkPermissionTo(), so
     * this single override also covers direct hasAnyPermission() calls made
     * outside Laravel's Gate (e.g. TimetableGrid's defense-in-depth checks),
     * not just $user->can().
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $permissionName = is_string($permission) ? $permission : $permission->name;
        if ($this->isActive() && $this->isSuperAdmin() && ! in_array($permissionName, self::protectedPolicyAbilities(), true)) {
            return true;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    public function canViewCashReports(): bool
    {
        return $this->can('view cash reports');
    }

    public function canExportCashReports(): bool
    {
        return $this->can('export cash reports');
    }
}
