<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {

        $permissions = [

            'manage students',
            'manage enrollments',
            'manage invoices',
            'manage cash',
            'view cash reports',
            'manage users',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions($permissions);

        $accountant = Role::firstOrCreate(['name' => 'accountant']);
        $accountant->syncPermissions([
            'manage invoices',
            'manage cash',
            'view cash reports',
        ]);

        $reception = Role::firstOrCreate(['name' => 'reception']);
        $reception->syncPermissions([
            'manage students',
            'manage enrollments',
        ]);
    }
}