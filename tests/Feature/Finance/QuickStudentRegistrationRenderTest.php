<?php

namespace Tests\Feature\Finance;

use App\Models\Grade;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuickStudentRegistrationRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_loads_grades_by_existing_level_then_id_without_grades_order_column(): void
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('accountant');

        $stage = Stage::create(['name' => 'Средняя школа', 'order' => 1, 'is_active' => true]);
        Grade::forceCreate(['stage_id' => $stage->id, 'name' => '10 класс', 'level' => 10]);
        Grade::forceCreate(['stage_id' => $stage->id, 'name' => '2 класс', 'level' => 2]);

        $this->assertTrue(Schema::hasColumn('grades', 'level'));
        $this->assertFalse(Schema::hasColumn('grades', 'order'));

        $this->actingAs($user)
            ->get(route('dashboard.quick-registration.create'))
            ->assertOk()
            ->assertSeeInOrder(['2 класс', '10 класс']);
    }
}
