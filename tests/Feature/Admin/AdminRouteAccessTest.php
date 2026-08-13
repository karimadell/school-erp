<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRouteAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The admin routes are gated by the specific permissions the 'admin'
     * role carries (permission:manage users / manage roles / view audit
     * logs) AND by EnsureAdministrativePortalAccess. Seed the real
     * role/permission matrix so the 'admin' role actually holds those
     * permissions, and mark the user active so it clears the portal.
     */
    public function test_admin_can_access_admin_users_roles_and_audit_logs(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $admin = User::factory()->create(['is_active' => true]);
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
        (new RolesAndPermissionsSeeder)->run();
        $admin = User::factory()->create(['is_active' => true]);
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
        // A portal-eligible but non-admin user ('reception', active): it clears
        // EnsureAdministrativePortalAccess and therefore reaches the admin
        // routes' permission gate, which denies it with a real 403 — rather
        // than being bounced by the portal middleware before it gets there.
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('reception');

        $this->actingAs($user)->get(route('dashboard.admin.users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.admin.roles.index'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.admin.audit.logs.index'))->assertForbidden();
    }
}
