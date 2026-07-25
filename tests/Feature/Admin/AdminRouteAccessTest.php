<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminRouteAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These routes are wrapped in middleware('role:admin'). The 'role'
     * middleware alias was previously unregistered, so every request here
     * threw "Target class [role] does not exist" regardless of role.
     */
    public function test_admin_can_access_admin_users_roles_and_audit_logs(): void
    {
        $admin = User::factory()->create();
        Role::findOrCreate('admin', 'web');
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('dashboard.admin.users.index'))->assertOk();
        $this->actingAs($admin)->get(route('dashboard.admin.roles.index'))->assertOk();
        $this->actingAs($admin)->get(route('dashboard.admin.audit.logs.index'))->assertOk();
    }

    /**
     * AuditLog previously had $timestamps = false, but every AuditLog::create()
     * call site relies on Eloquent to populate created_at automatically. That
     * left created_at NULL on every real row, crashing this view's
     * $log->created_at->format(...) call as soon as any audit entry existed.
     */
    public function test_audit_log_page_renders_an_actual_log_entry(): void
    {
        $admin = User::factory()->create();
        Role::findOrCreate('admin', 'web');
        $admin->assignRole('admin');

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'change user role',
            'model' => 'User',
            'model_id' => $admin->id,
            'ip' => '127.0.0.1',
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard.admin.audit.logs.index'));

        $response->assertOk();
        $this->assertNotNull(AuditLog::first()->created_at);
    }

    public function test_non_admin_is_forbidden_from_admin_routes(): void
    {
        $user = User::factory()->create();
        Role::findOrCreate('admin', 'web');

        $this->actingAs($user)->get(route('dashboard.admin.users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.admin.roles.index'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.admin.audit.logs.index'))->assertForbidden();
    }
}
