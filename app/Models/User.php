<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/* Spatie */
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory;
    use Notifiable;
    use HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
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
     * Portal is teacher- (and admin-, for oversight) only.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->hasAnyRole(['admin', 'school-admin', 'accountant', 'reception', 'principal']),
            'teacher' => $this->hasAnyRole(['admin', 'teacher']),
            default => false,
        };
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