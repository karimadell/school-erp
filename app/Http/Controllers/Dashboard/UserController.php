<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
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
        $users = User::with('roles')->latest()->get();
        $roles = Role::orderBy('name')->get();

        return view('dashboard.users.index', compact('users', 'roles'));
    }

    /**
     * إنشاء مستخدم جديد
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role'     => 'required|exists:roles,name',
        ]);

        $user = User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'is_active' => true,
        ]);

        $user->assignRole($request->role);

        return back()->with('success', 'تم إنشاء المستخدم بنجاح');
    }

    /**
     * تحديث المستخدم
     */
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role'  => 'required|exists:roles,name',
        ]);

        $user->update([
            'name'  => $request->name,
            'email' => $request->email,
        ]);

        // تحديث الدور
        $user->syncRoles([$request->role]);

        return back()->with('success', 'تم تعديل المستخدم');
    }

    /**
     * تعطيل / تفعيل مستخدم
     */
    public function toggle(User $user)
    {
        $user->update([
            'is_active' => ! $user->is_active,
        ]);

        return back()->with('success', 'تم تحديث حالة المستخدم');
    }

    /**
     * حذف مستخدم
     */
    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'لا يمكنك حذف نفسك');
        }

        $user->delete();

        return back()->with('success', 'تم حذف المستخدم');
    }
}