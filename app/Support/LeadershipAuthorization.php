<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class LeadershipAuthorization
{
    /**
     * @return array<int, string>
     */
    public static function protectedPermissionNames(): array
    {
        return ['manage permissions', 'unlock historical academic year'];
    }

    public static function authorizeRoleName(User $actor, string $name): void
    {
        if ($name === 'super-admin' && ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Создавать роль super-admin может только super-admin.');
        }
    }

    /**
     * @param array<int, int|string> $roleIds
     */
    public static function authorizeRoleAssignment(User $actor, array $roleIds, ?User $target = null): void
    {
        $assignsProtectedRole = Role::query()
            ->whereIn('id', $roleIds)
            ->where(function ($query) {
                $query->where('name', 'super-admin')
                    ->orWhereHas('permissions', fn ($permissions) => $permissions
                        ->whereIn('name', self::protectedPermissionNames()));
            })
            ->exists();

        if ($assignsProtectedRole && ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Назначать защищённые системные роли может только super-admin.');
        }

        $assignsSuperAdmin = Role::query()
            ->whereIn('id', $roleIds)
            ->where('name', 'super-admin')
            ->exists();

        if ($target?->isSuperAdmin() && ! $assignsSuperAdmin) {
            if (! $actor->isSuperAdmin() || $target->isLastActiveSuperAdmin()) {
                throw new AuthorizationException('Нельзя удалить роль у последнего активного super-admin.');
            }
        }
    }

    /**
     * @param array<int, int|string> $permissionIds
     */
    public static function authorizeProtectedPermissions(User $actor, array $permissionIds): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $protected = Permission::query()
            ->whereIn('id', $permissionIds)
            ->whereIn('name', self::protectedPermissionNames())
            ->exists();

        if ($protected) {
            throw new AuthorizationException('Защищённые системные разрешения доступны только super-admin.');
        }
    }
}
