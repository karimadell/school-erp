<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

/**
 * Batch 6: the complete role/permission matrix. Deny by default — every
 * permission a role has is explicitly listed below, nothing is inherited
 * implicitly. 'admin' is the pre-existing, unrenamed role (AdminUserSeeder
 * and tests reference it by this exact name) and plays the "Super Admin"
 * part: the only role with system-configuration permissions (manage users/
 * roles/permissions). 'school-admin' is new: full operational access
 * without system configuration.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // People
            'view students', 'create students', 'update students', 'delete students', 'manage students',
            'manage teachers',
            'view enrollments', 'create enrollments', 'update enrollments', 'delete enrollments',

            // Academic management
            'manage subjects',
            'manage stages',
            'manage grades',
            'manage classes',
            'manage academic years',
            'manage journal entries',

            // Item 2 (Batch 10): kept separate from 'manage academic years'
            // and out of the general $permissions list on purpose — this
            // is not synced to school-admin/principal via array_diff below,
            // it is granted to 'admin' only, explicitly, further down.
            'unlock historical academic year',

            // Item 8 (Batch 10 / C1): Curriculum admin. Admin-only per
            // approved decision — not extended to school-admin or
            // principal for this initial implementation, even though both
            // hold every other academic-management permission.
            'manage curriculum',

            // Batch 4 (Timetable Permissions): dedicated timetable
            // permissions, kept separate from 'manage classes'. Admin-only
            // per approved decision — not auto-extended to any other role.
            // 'manage timetable' implies 'view timetable' in application
            // logic (TimetableGrid's own checks), not via Spatie, which has
            // no permission hierarchy.
            'view timetable',
            'manage timetable',

            // Finance
            'view invoices', 'manage invoices',
            // F4D-A Mass Billing: separated into view (read history/results),
            // manage (draft/edit/preview) and execute (issue many invoices at
            // once) so the sensitive execute step is gated independently of
            // read/manage access.
            'view mass billing',
            'manage mass billing',
            'execute mass billing',
            'manage fees',
            'manage fee prices',
            'manage expenses',
            'manage student service subscriptions',
            'override service prices',
            'view student balances',
            'manage cash',
            'view cash reports',
            // Phase 3 — cash-drawer sessions. Separated by risk: view (read
            // history/detail), open (start a shift), close (reconcile), and a
            // dedicated higher-risk permission to accept a non-zero variance.
            'view cash sessions',
            'open cash sessions',
            'close cash sessions',
            'close cash sessions with variance',

            // Leadership oversight
            'view audit logs',

            // System configuration (Super Admin only)
            'manage users',
            'manage roles',
            'manage permissions',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Protected Super Admin — full access and the application-wide bypass.
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $superAdmin->syncPermissions($permissions);

        // Existing admin role remains fully permissioned for compatibility,
        // but it is no longer the protected application-wide bypass role.
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions($permissions);

        // 2. School Admin — full operational access, no system
        // configuration (manage users/roles/permissions).
        $schoolAdmin = Role::firstOrCreate(['name' => 'school-admin']);
        $schoolAdmin->syncPermissions(array_values(array_diff($permissions, [
            'manage users',
            'manage roles',
            'manage permissions',
            // Item 2: unlock is admin-only for the initial implementation
            // — not extended to school-admin, even though school-admin
            // otherwise mirrors admin's full operational permission set.
            'unlock historical academic year',
            // Item 8: same reasoning — Curriculum admin is admin-only.
            'manage curriculum',
            // Batch 4: same reasoning — Timetable permissions are
            // admin-only, not auto-extended to school-admin.
            'view timetable',
            'manage timetable',
        ])));

        // 3. Accountant — full access to fees/fee prices/invoices/expenses/
        // subscriptions; read-only students/enrollments; no academic
        // management, no teachers, no roles/permissions, no price override
        // (a separately-permissioned, more sensitive action).
        $accountant = Role::firstOrCreate(['name' => 'accountant']);
        $accountant->syncPermissions([
            'view students',
            'view enrollments',
            'view invoices', 'manage invoices',
            'view mass billing',
            'manage mass billing',
            'execute mass billing',
            'manage fees',
            'manage fee prices',
            'manage expenses',
            'manage student service subscriptions',
            // Phase 3: an accountant runs the drawer day to day — open, close
            // and reconcile — but accepting a variance is a separately-gated,
            // higher-risk action reserved for admin/school-admin/principal.
            'view cash sessions',
            'open cash sessions',
            'close cash sessions',
        ]);

        // 4. Reception — students/enrollments create+view+update only (no
        // delete); read-only invoices and student balances; no price
        // changes, no override, no deleting financial records, no expenses,
        // no roles/permissions.
        $reception = Role::firstOrCreate(['name' => 'reception']);
        $reception->syncPermissions([
            'view students', 'create students', 'update students', 'manage students',
            'view enrollments', 'create enrollments', 'update enrollments',
            'view invoices',
            'view student balances',
        ]);

        // 5. Teacher — Teacher Portal only. No admin-panel permissions of
        // any kind; record-level scoping to assigned classes/students is
        // enforced via TeacherAssignment (Batch 8).
        Role::firstOrCreate(['name' => 'teacher']);

        // 6. Principal / School Director — complete operational access and
        // guarded user/role administration, excluding protected system-level
        // permission management and historical unlock authority.
        $principal = Role::firstOrCreate(['name' => 'principal']);
        $principal->syncPermissions(array_values(array_diff($permissions, [
            'unlock historical academic year',
            'manage permissions',
        ])));
    }
}
