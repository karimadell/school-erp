<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassResource\Pages\TimetableGrid;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Batch 4 / Timetable Permissions (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
 * view timetable / manage timetable, enforced via inline abort_unless()
 * checks in TimetableGrid — no new Policy class, per approved decision.
 *
 * "Cannot mount" is tested via a real mount() call, since the
 * abort_unless() fires on the very first line, before the pre-existing,
 * unrelated Period::orderBy('order') bug (see TimetableGridGenerateTest's
 * doc comment) is ever reached. Proving a *successful* mount() end to
 * end would hit that same unrelated bug regardless of authorization, so
 * "can access" is instead verified via TimetableGrid::canAccess() — the
 * exact same permission condition mount()'s guard uses, per this
 * batch's defense-in-depth design. Write-action checks (saveLesson,
 * moveLesson, generateTimetable) are exercised directly, matching every
 * other Timetable test file's established pattern of bypassing mount().
 */
class TimetableGridAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    protected function makeYear(): AcademicYear
    {
        return AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
    }

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

    protected function makeDay(string $code = 'mon', int $order = 0): Day
    {
        return Day::create(['name' => $code, 'code' => $code, 'order' => $order]);
    }

    protected function makeGrid(SchoolClass $class): TimetableGrid
    {
        $grid = new TimetableGrid();
        $grid->classId = $class->id;

        return $grid;
    }

    public function test_a_user_with_no_permission_cannot_mount(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $class = $this->makeClass();

        $this->assertFalse(TimetableGrid::canAccess());

        try {
            (new TimetableGrid())->mount($class->id);
            $this->fail('Expected mount() to abort with a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_view_only_can_access_the_page_but_cannot_save_move_or_generate(): void
    {
        $user = $this->makeUserWithPermissions(['view timetable']);
        $this->actingAs($user);

        $this->assertTrue(TimetableGrid::canAccess());

        $year = $this->makeYear();
        $class = $this->makeClass();
        $day = $this->makeDay();
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $grid = $this->makeGrid($class);
        $grid->days = collect([$day]);
        $grid->periods = collect([$period]);
        $grid->selectedSubject = [$day->id => [$period->id => $subject->id]];
        $grid->selectedTeacher = [$day->id => [$period->id => $teacher->id]];

        try {
            $grid->saveLesson($day->id, $period->id);
            $this->fail('Expected saveLesson() to abort with a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $lesson = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);
        $grid->dragLessonId = $lesson->id;

        try {
            $grid->moveLesson($day->id, $period->id);
            $this->fail('Expected moveLesson() to abort with a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        try {
            $grid->generateTimetable();
            $this->fail('Expected generateTimetable() to abort with a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(1, Timetable::where('class_id', $class->id)->count());
    }

    public function test_manage_only_can_access_the_page_and_perform_all_protected_actions(): void
    {
        $user = $this->makeUserWithPermissions(['manage timetable']);
        $this->actingAs($user);

        $this->assertTrue(TimetableGrid::canAccess());

        $year = $this->makeYear();
        $class = $this->makeClass();
        $day = $this->makeDay();
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 5, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$day->id => [$period->id => $subject->id]];
        $grid->selectedTeacher = [$day->id => [$period->id => $teacher->id]];
        $grid->saveLesson($day->id, $period->id);

        $this->assertSame(1, Timetable::where('class_id', $class->id)->count());

        $lesson = Timetable::where('class_id', $class->id)->first();
        $grid->dragLessonId = $lesson->id;
        $grid->moveLesson($day->id, $targetPeriod->id);

        $this->assertSame($targetPeriod->id, $lesson->fresh()->period_id);

        $grid->days = collect([$day]);
        $grid->periods = collect([$period, $targetPeriod]);
        $grid->generateTimetable();

        $this->assertGreaterThan(0, Timetable::where('class_id', $class->id)->count());
    }

    public function test_manage_timetable_implies_page_access_without_a_separate_view_timetable_grant(): void
    {
        $user = $this->makeUserWithPermissions(['manage timetable']);
        $this->actingAs($user);

        Permission::firstOrCreate(['name' => 'view timetable']);
        $this->assertFalse($user->hasPermissionTo('view timetable'));
        $this->assertTrue(TimetableGrid::canAccess());
    }
}
