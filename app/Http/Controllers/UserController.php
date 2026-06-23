<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * عرض كل المستخدمين
     */
    public function index()
    {
        $this->authorizePermission('users.view');

        return User::with('roles', 'permissions')->get();
    }

    /**
     * إنشاء مستخدم جديد + Role
     */
    public function store(Request $request)
    {
        $this->authorizePermission('users.create');

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users',
            'password' => 'required|min:6',
            'role'     => 'required|exists:roles,name',
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $user->assignRole($data['role']);

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user->load('roles'),
        ], 201);
    }

    /**
     * تغيير Role المستخدم
     */
    public function updateRole(Request $request, $id)
    {
        $this->authorizePermission('users.edit');

        $data = $request->validate([
            'role' => 'required|exists:roles,name',
        ]);

        $user = User::findOrFail($id);
        $user->syncRoles([$data['role']]);

        return response()->json([
            'message' => 'Role updated successfully',
            'user' => $user->load('roles'),
        ]);
    }

    /**
     * حذف مستخدم
     */
    public function destroy($id)
    {
        $this->authorizePermission('users.delete');

        $user = User::findOrFail($id);
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully',
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