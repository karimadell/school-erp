<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    /**
     * List roles
     */
    public function index(): View
    {
        $roles = Role::with('permissions')->get();

        return view('dashboard.admin.roles.index', compact('roles'));
    }

    /**
     * Show create role form
     */
    public function create(): View
    {
        $permissions = Permission::all();

        return view('dashboard.admin.roles.create', compact('permissions'));
    }

    /**
     * Store role
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'unique:roles,name'],
            'permissions' => ['array'],
        ]);

        $role = Role::create(['name' => $data['name']]);

        if (!empty($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        return redirect()
            ->route('dashboard.admin.roles.index')
            ->with('success', 'Role created successfully');
    }

    /**
     * Edit role
     */
    public function edit(Role $role): View
    {
        $permissions = Permission::all();

        return view('dashboard.admin.roles.edit', compact('role', 'permissions'));
    }

    /**
     * Update role
     */
    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'permissions' => ['array'],
        ]);

        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()
            ->route('dashboard.admin.roles.index')
            ->with('success', 'Role updated successfully');
    }

    /**
     * Delete role
     */
    public function destroy(Role $role): RedirectResponse
    {
        if ($role->name === 'admin') {
            return back()->with('error', 'Admin role cannot be deleted');
        }

        $role->delete();

        return back()->with('success', 'Role deleted successfully');
    }
}