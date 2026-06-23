<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles & permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        /*
        |--------------------------------------------------------------------------
        | Permissions
        |--------------------------------------------------------------------------
        */
        $permissions = [

            /* ===== Dashboard ===== */
            'view dashboard',

            /* ===== Students ===== */
            'view students',
            'create students',
            'edit students',
            'delete students',

            /* ===== Invoices (تفصيلي) ===== */
            'view invoices',
            'create invoices',
            'pay invoices',
            'print invoices',

            /* ===== Cash (تفصيلي) ===== */
            'view cash transactions',
            'create cash in',
            'create cash out',

            /* ===== Reports ===== */
            'view cash reports',
            'export cash reports',

            /* ===== Group / Legacy Permissions ===== */
            // ⚠️ مهمين عشان الشغل القديم
            'manage invoices',
            'manage cash',

            /* ===== Admin ===== */
            'manage users',
            'manage settings',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        /*
        |--------------------------------------------------------------------------
        | Roles
        |--------------------------------------------------------------------------
        */
        $admin      = Role::firstOrCreate(['name' => 'admin']);
        $accountant = Role::firstOrCreate(['name' => 'accountant']);
        $cashier    = Role::firstOrCreate(['name' => 'cashier']);

        /*
        |--------------------------------------------------------------------------
        | Assign Permissions
        |--------------------------------------------------------------------------
        */

        // ✅ Admin → كل الصلاحيات
        $admin->syncPermissions(Permission::all());

        // ✅ Accountant
        $accountant->syncPermissions([
            'view dashboard',

            // Students
            'view students',

            // Invoices
            'view invoices',
            'print invoices',
            'manage invoices', // legacy

            // Cash
            'view cash transactions',
            'view cash reports',
            'export cash reports',
            'manage cash', // legacy
        ]);

        // ✅ Cashier
        $cashier->syncPermissions([
            'view dashboard',

            // Students
            'view students',
            'create students',

            // Invoices
            'view invoices',
            'create invoices',
            'pay invoices',
            'print invoices',
            'manage invoices', // legacy

            // Cash
            'view cash transactions',
            'create cash in',
            'create cash out',
            'manage cash', // legacy
        ]);
    }
}