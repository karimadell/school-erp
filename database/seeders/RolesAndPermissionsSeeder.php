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
            'view students', 'create students', 'update students', 'delete students',
            'manage teachers',
            'view enrollments', 'create enrollments', 'update enrollments', 'delete enrollments',

            // Academic management
            'manage subjects',
            'manage stages',
            'manage grades',
            'manage classes',
            'manage academic years',
            'manage journal entries',

            // Finance
            'view invoices', 'manage invoices',
            'manage fees',
            'manage fee prices',
            'manage expenses',
            'manage student service subscriptions',
            'override service prices',
            'view student balances',
            'manage cash',
            'view cash reports',

            // System configuration (Super Admin only)
            'manage users',
            'manage roles',
            'manage permissions',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // 1. Super Admin — full access to all modules and all actions,
        // including system configuration. Role name kept as 'admin'
        // (unrenamed) since AdminUserSeeder and existing tests reference
        // it by this exact name.
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions($permissions);

        // 2. School Admin — full operational access, no system
        // configuration (manage users/roles/permissions).
        $schoolAdmin = Role::firstOrCreate(['name' => 'school-admin']);
        $schoolAdmin->syncPermissions(array_values(array_diff($permissions, [
            'manage users',
            'manage roles',
            'manage permissions',
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
            'manage fees',
            'manage fee prices',
            'manage expenses',
            'manage student service subscriptions',
        ]);

        // 4. Reception — students/enrollments create+view+update only (no
        // delete); read-only invoices and student balances; no price
        // changes, no override, no deleting financial records, no expenses,
        // no roles/permissions.
        $reception = Role::firstOrCreate(['name' => 'reception']);
        $reception->syncPermissions([
            'view students', 'create students', 'update students',
            'view enrollments', 'create enrollments', 'update enrollments',
            'view invoices',
            'view student balances',
        ]);

        // 5. Teacher — Teacher Portal only. No admin-panel permissions of
        // any kind; record-level scoping to assigned classes/students is
        // enforced via TeacherAssignment (Batch 8).
        Role::firstOrCreate(['name' => 'teacher']);

        // 6. Principal / Academic Manager — full academic permissions,
        // read-only finance, explicitly no fee price editing. Includes
        // journal oversight (Batch 9).
        $principal = Role::firstOrCreate(['name' => 'principal']);
        $principal->syncPermissions([
            'manage subjects',
            'manage stages',
            'manage grades',
            'manage classes',
            'manage academic years',
            'manage journal entries',
            'view invoices',
            'view student balances',
            'view cash reports',
        ]);
    }
}
