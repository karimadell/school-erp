<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassResource\Pages\TimetableGrid;
use App\Models\Day;
use App\Models\Grade;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TimetableGridPageRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_user_can_render_an_empty_timetable_with_days_and_periods_in_number_order(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'view timetable']);
        $user->givePermissionTo('view timetable');

        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => '1A',
            'name_ar' => '1A',
            'name_ru' => '1A',
        ]);

        $day = Day::create(['name' => 'Monday', 'code' => 'mon', 'order' => 1]);
        Period::create(['number' => 3, 'start_time' => '09:40', 'end_time' => '10:25']);
        Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);

        $component = Livewire::actingAs($user)
            ->test(TimetableGrid::class, ['record' => $class->id])
            ->assertOk()
            ->assertSee($day->name);

        $this->assertSame([1, 2, 3], $component->get('periods')->pluck('number')->all());
        $this->assertDatabaseCount('timetables', 0);
    }
}
