<?php

namespace Database\Seeders;

use App\Support\TransportPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Additive repair for the canonical Transport permission matrix only. */
class TransportPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $roles = collect(['super-admin', 'admin', 'school-admin', 'accountant'])
                ->mapWithKeys(fn (string $name) => [$name => Role::query()->where('name', $name)->first()]);

            if ($roles->contains(null)) {
                throw ValidationException::withMessages([
                    'roles' => 'Canonical Transport permission repair requires all existing target roles; no roles were created.',
                ]);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
            foreach (TransportPermissions::ALL as $name) {
                Permission::firstOrCreate(['name' => $name]);
            }

            foreach (['super-admin', 'admin', 'school-admin'] as $role) {
                $roles[$role]->givePermissionTo(TransportPermissions::ALL);
            }
            $roles['accountant']->givePermissionTo(TransportPermissions::MANAGE_ASSIGNMENTS);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }
}
