<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassResource;
use App\Filament\Resources\ClassResource\Pages\ListClasses;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Day;
use App\Models\Grade;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\Timetable;
use App\Models\User;
use App\Services\CurriculumAwareTimetableConflictChecker;
use App\Support\TimetableSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Batch 13 / Legacy Timetable Controller Removal
 * (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md §6): the deprecated
 * TimetableController, its 9 dashboard.timetable.* routes, and its 4
 * Blade views (index/create/pdf/show — edit was already removed in
 * Batch 8) have been fully removed. No navigation ever linked to them
 * (confirmed at inspection time), so this is pure dead-surface removal.
 * The canonical timetable UI, ClassResource -> TimetableGrid, and the
 * shared CurriculumAwareTimetableConflictChecker engine it (and,
 * formerly, the legacy controller) resolves are untouched by this batch
 * — asserted below rather than assumed.
 */
class TimetableControllerDecommissioningTest extends TestCase
{
    use RefreshDatabase;

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary ' . uniqid()]);
        $grade = Grade::create(['name' => 'Grade ' . uniqid(), 'stage_id' => $stage->id]);

        return SchoolClass::create(['grade_id' => $grade->id, 'code' => 'C-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    protected function makeSubject(): Subject
    {
        return Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    protected function makeTeacher(): Teacher
    {
        return Teacher::create(['first_name' => 'A', 'last_name' => 'B-' . uniqid(), 'is_active' => true]);
    }

    public function test_the_former_controller_route_names_are_no_longer_registered(): void
    {
        $this->assertFalse(Route::has('dashboard.timetable.index'));
        $this->assertFalse(Route::has('dashboard.timetable.create'));
        $this->assertFalse(Route::has('dashboard.timetable.store'));
        $this->assertFalse(Route::has('dashboard.timetable.update'));
        $this->assertFalse(Route::has('dashboard.timetable.destroy'));
        $this->assertFalse(Route::has('dashboard.timetable.move'));
        $this->assertFalse(Route::has('dashboard.timetable.subject.teachers'));
        $this->assertFalse(Route::has('dashboard.timetable.pdf'));
        $this->assertFalse(Route::has('dashboard.timetable.show'));

        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter();

        $this->assertFalse($names->contains(fn ($name) => str_starts_with($name, 'dashboard.timetable.')));
    }

    public function test_the_former_controller_class_no_longer_exists(): void
    {
        $this->assertFalse(class_exists(\App\Http\Controllers\Dashboard\TimetableController::class));
    }

    public function test_direct_access_to_every_former_legacy_url_returns_404(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'manage timetable']);
        $user->givePermissionTo('manage timetable');

        $this->actingAs($user)->get('/dashboard/timetable')->assertNotFound();
        $this->actingAs($user)->get('/dashboard/timetable/create')->assertNotFound();
        $this->actingAs($user)->post('/dashboard/timetable', [])->assertNotFound();
        $this->actingAs($user)->put('/dashboard/timetable/1', [])->assertNotFound();
        $this->actingAs($user)->delete('/dashboard/timetable/1')->assertNotFound();
        $this->actingAs($user)->post('/dashboard/timetable/1/move', [])->assertNotFound();
        $this->actingAs($user)->get('/dashboard/timetable/subject/1/teachers')->assertNotFound();
        $this->actingAs($user)->get('/dashboard/timetable/class/1/pdf')->assertNotFound();
        $this->actingAs($user)->get('/dashboard/timetable/class/1')->assertNotFound();
    }

    public function test_the_timetable_model_and_database_table_remain_fully_available(): void
    {
        $this->assertTrue(Schema::hasTable('timetables'));

        $class = $this->makeClass();
        $day = Day::create(['name' => 'Day-' . uniqid(), 'code' => 'd-' . uniqid(), 'order' => 0]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();

        $timetable = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $this->assertDatabaseHas('timetables', ['id' => $timetable->id, 'class_id' => $class->id]);
        $this->assertSame(1, Timetable::where('class_id', $class->id)->count());
    }

    public function test_curriculum_aware_timetable_conflict_checker_remains_available_and_functional(): void
    {
        $year = AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $class = $this->makeClass();
        $day = Day::create(['name' => 'Day-' . uniqid(), 'code' => 'd-' . uniqid(), 'order' => 0]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 5, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $slot = new TimetableSlot(
            classId: $class->id, dayId: $day->id, periodId: $period->id,
            teacherId: $teacher->id, subjectId: $subject->id,
        );

        $result = app(CurriculumAwareTimetableConflictChecker::class)->check($slot);

        $this->assertFalse($result->hasConflict());

        // A subject outside the curriculum is still rejected — the
        // engine's rules are unaffected by the legacy controller's removal.
        $unrelatedSubject = $this->makeSubject();
        $rejectedSlot = new TimetableSlot(
            classId: $class->id, dayId: $day->id, periodId: $period->id,
            teacherId: $teacher->id, subjectId: $unrelatedSubject->id,
        );

        $rejectedResult = app(CurriculumAwareTimetableConflictChecker::class)->check($rejectedSlot);

        $this->assertTrue($rejectedResult->hasConflict());
        $this->assertSame('timetable.subject_not_in_curriculum', $rejectedResult->first());
    }

    public function test_the_canonical_class_resource_timetable_grid_flow_remains_unaffected(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'view timetable']);
        $user->givePermissionTo('view timetable');

        $class = $this->makeClass();

        Livewire::actingAs($user)
            ->test(ListClasses::class)
            ->assertTableActionVisible('timetable', $class);

        $this->actingAs($user);

        $expectedUrl = route('filament.admin.resources.classes.timetable', ['record' => $class->id]);

        $this->assertSame($expectedUrl, ClassResource::getUrl('timetable', ['record' => $class]));
    }
}
