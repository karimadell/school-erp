<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimetableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TimetableController::show() previously called Day::orderBy('order'),
     * but the days table has no 'order' column (only id/name), which threw
     * a SQL error on every visit to this page.
     */
    public function test_timetable_show_page_renders_for_a_class(): void
    {
        $user = User::factory()->create();
        $stage = Stage::create(['name' => 'Elementary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
        $class = SchoolClass::forceCreate(['code' => 'A', 'name_ar' => 'A', 'name_ru' => 'A', 'grade_id' => $grade->id]);

        $response = $this->actingAs($user)
            ->get(route('dashboard.timetable.show', $class));

        $response->assertOk();
    }
}
