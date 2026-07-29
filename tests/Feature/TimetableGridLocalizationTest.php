<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassResource\Pages\TimetableGrid;
use App\Models\Day;
use App\Models\Grade;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Mechanisms\ExtendBlade\ExtendBlade;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Batch 11 / Localization: timetable-grid.blade.php hardcoded "Subject",
 * "Teacher", "Save" and "Generate Smart Timetable" in English inside the
 * canonical, actively-used timetable UI. All four now resolve through
 * timetable.* translation keys (subject/teacher/save already existed;
 * generate_smart_timetable is new).
 *
 * Rendering approach: TimetableGrid::mount() runs Period::orderBy('order'),
 * a separate, pre-existing, unrelated bug (the periods table has no
 * 'order' column — see TimetableGridGenerateTest's and
 * TimetableGridAuthorizationTest's doc comments for the same issue), so a
 * real HTTP/Livewire::test() round trip through mount() cannot be used
 * here either, exactly as in every other TimetableGrid test file. Instead
 * of bypassing render() entirely, this test drives the actual Blade view
 * through Livewire's real render path: ExtendBlade::startLivewireRendering()
 * is the same public hook Livewire's own HandleComponents::render() calls
 * before evaluating the view, and is what makes $this->getLesson()/
 * $this->assignedTeachersFor() calls inside the blade file resolve against
 * the component instance rather than the view engine. This proves the
 * genuine rendered output, not just the raw template source.
 */
class TimetableGridLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsTimetableManager(): User
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'manage timetable']);
        $user->givePermissionTo('manage timetable');
        $this->actingAs($user);

        return $user;
    }

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary ' . uniqid()]);
        $grade = Grade::create(['name' => 'Grade ' . uniqid(), 'stage_id' => $stage->id]);

        return SchoolClass::create(['grade_id' => $grade->id, 'code' => 'C-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    protected function renderGrid(SchoolClass $class, Day $day, Period $period, Subject $subject): string
    {
        $grid = new TimetableGrid();
        $grid->classId = $class->id;
        $grid->days = collect([$day]);
        $grid->periods = collect([$period]);
        $grid->subjects = collect([$subject]);
        $grid->selectedSubject = [];
        $grid->selectedTeacher = [];

        $extendBlade = app(ExtendBlade::class);
        $extendBlade->startLivewireRendering($grid);

        try {
            $view = $grid->render()->with([
                'days' => $grid->days,
                'periods' => $grid->periods,
                'subjects' => $grid->subjects,
                'selectedSubject' => $grid->selectedSubject,
                'selectedTeacher' => $grid->selectedTeacher,
            ]);

            return $view->render();
        } finally {
            $extendBlade->endLivewireRendering();
        }
    }

    public function test_the_rendered_page_has_no_hardcoded_english_and_shows_the_russian_translations(): void
    {
        app()->setLocale('ru');
        $this->actingAsTimetableManager();

        $class = $this->makeClass();
        $day = Day::create(['name' => 'Day-' . uniqid(), 'code' => 'd-' . uniqid(), 'order' => 0]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $subject = Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);

        $html = $this->renderGrid($class, $day, $period, $subject);

        $this->assertStringNotContainsString('Generate Smart Timetable', $html);
        $this->assertStringNotContainsString('>Subject<', $html);
        $this->assertStringNotContainsString('>Teacher<', $html);
        $this->assertStringNotContainsString('>Save<', $html);

        $this->assertStringContainsString(__('timetable.generate_smart_timetable'), $html);
        $this->assertStringContainsString(__('timetable.subject'), $html);
        $this->assertStringContainsString(__('timetable.teacher'), $html);
        $this->assertStringContainsString(__('timetable.save'), $html);

        $this->assertSame('Сгенерировать умное расписание', __('timetable.generate_smart_timetable'));
        $this->assertSame('Предмет', __('timetable.subject'));
        $this->assertSame('Преподаватель', __('timetable.teacher'));
        $this->assertSame('Сохранить', __('timetable.save'));
    }
}
