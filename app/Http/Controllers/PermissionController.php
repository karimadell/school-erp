<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionController extends Controller
{
    /**
     * كل الصلاحيات (للـ checklist)
     */
    public function allPermissions()
    {
        $this->authorizePermission('users.view');

        return Permission::orderBy('name')->get();
    }

    /**
     * كل الـ Roles مع صلاحياتهم
     */
    public function rolesWithPermissions()
    {
        $this->authorizePermission('users.view');

        return Role::with('permissions')->get();
    }

    /**
     * تحديث صلاحيات Role (Checklist)
     */
    public function updateRolePermissions(Request $request, $roleId)
    {
        $this->authorizePermission('users.edit');

        $data = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role = Role::findOrFail($roleId);
        $role->syncPermissions($data['permissions']);

        return response()->json([
            'message' => 'Permissions updated successfully',
            'role' => $role->load('permissions'),
        ]);
    }

    /**
     * تحديث صلاحيات مستخدم مباشرة
     */
    public function updateUserPermissions(Request $request, $userId)
    {
        $this->authorizePermission('users.edit');

        $data = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $user = \App\Models\User::findOrFail($userId);
        $user->syncPermissions($data['permissions']);

        return response()->json([
            'message' => 'User permissions updated successfully',
            'user' => $user->load('permissions'),
        ]);
    }

    /**
     * Helper
     */
    private function authorizePermission(string $permission): void
    {
        if (!auth()->user() || !auth()->user()->can($permission)) {
            abort(403, 'Unauthorized');
        }
    }
}