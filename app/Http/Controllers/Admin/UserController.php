<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AuditLog;
use App\Notifications\RoleChangedNotification;
use Spatie\Permission\Models\Role;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class UserController extends Controller
{
    /**
     * List users with roles
     */
    public function index(): View
    {
        $users = User::with('roles')
            ->orderBy('name')
            ->paginate(15);

        $roles = Role::orderBy('name')->get();

        return view('dashboard.admin.users.index', compact(
            'users',
            'roles'
        ));
    }

    /**
     * Update user role
     */
    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'exists:roles,name'],
        ]);

        // 🔒 منع الأدمن من تغيير دوره بنفسه
        if (auth()->id() === $user->id) {
            return back()->with('error', '❌ لا يمكنك تغيير دورك بنفسك');
        }

        $oldRole = $user->roles->first()?->name ?? 'none';
        $newRole = $data['role'];

        if ($oldRole === $newRole) {
            return back()->with('info', 'لم يتم تغيير أي شيء');
        }

        // تحديث الدور
        $user->syncRoles([$newRole]);

        // 📜 Audit Log
        AuditLog::create([
            'user_id'  => auth()->id(),
            'action'   => 'change user role',
            'model'    => 'User',
            'model_id' => $user->id,
            'details'  => "From: {$oldRole} → To: {$newRole}",
            'ip'       => request()->ip(),
        ]);

        // 🚨 Email Notification
        $user->notify(
            new RoleChangedNotification(
                $newRole,
                auth()->user()->name
            )
        );

        return back()->with('success', '✅ تم تحديث دور المستخدم وإرسال إشعار بالبريد');
    }
}