<?php

namespace Tests\Feature\Transport;

use App\Models\Student;
use App\Support\TransportPermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TransportPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TransportPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_is_additive_idempotent_and_does_not_touch_operational_data(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        foreach (TransportPermissions::ALL as $name) {
            Permission::where('name', $name)->delete();
        }
        $unrelated = Permission::firstOrCreate(['name' => 'unrelated retained permission']);
        Role::findByName('admin')->givePermissionTo($unrelated);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Student::create(['name' => 'Untouched student', 'status' => Student::STATUS_ACTIVE]);

        $before = [
            'students' => Student::count(),
            'assignments' => DB::table('student_transport_assignments')->count(),
            'users' => DB::table('users')->count(),
        ];
        (new TransportPermissionsSeeder)->run();
        (new TransportPermissionsSeeder)->run();

        $this->assertSame(5, Permission::whereIn('name', TransportPermissions::ALL)->count());
        foreach (['super-admin', 'admin', 'school-admin'] as $role) {
            $this->assertEmpty(array_diff(TransportPermissions::ALL, Role::findByName($role)->permissions->pluck('name')->all()));
        }
        $this->assertTrue(Role::findByName('accountant')->hasPermissionTo(TransportPermissions::MANAGE_ASSIGNMENTS));
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('unrelated retained permission'));
        $this->assertSame($before, [
            'students' => Student::count(),
            'assignments' => DB::table('student_transport_assignments')->count(),
            'users' => DB::table('users')->count(),
        ]);
    }
}
