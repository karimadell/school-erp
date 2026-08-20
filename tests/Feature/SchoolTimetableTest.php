<?php

namespace Tests\Feature;

use App\Models\Day;
use App\Models\Grade;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\TimetableSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchoolTimetableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
    }

    protected function administrativeUser(?string $permission = null): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('school-admin');

        if ($permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    protected function teacherOnlyUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('teacher');
        Teacher::create([
            'user_id' => $user->id,
            'first_name' => 'Teacher',
            'last_name' => 'Portal',
            'is_active' => true,
        ]);

        return $user;
    }

    protected function makeClass(string $name, bool $active = true): SchoolClass
    {
        $stage = Stage::create(['name' => 'Stage '.$name.'-'.uniqid()]);
        $grade = Grade::create(['name' => 'Grade '.$name.'-'.uniqid(), 'stage_id' => $stage->id]);

        return SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'C-'.uniqid(),
            'name_ar' => $name,
            'name_ru' => $name,
            'is_active' => $active,
        ]);
    }

    protected function makeDay(string $code = 'sun', int $order = 1, ?string $name = null): Day
    {
        return Day::create(['code' => $code, 'order' => $order, 'name' => $name ?? $code]);
    }

    protected function makePeriod(int $number): Period
    {
        return Period::create([
            'number' => $number,
            'start_time' => sprintf('%02d:00', $number + 7),
            'end_time' => sprintf('%02d:45', $number + 7),
        ]);
    }

    protected function makeSubject(string $name): Subject
    {
        return Subject::create([
            'name_ru' => $name,
            'name_ar' => $name,
            'code' => 'S-'.uniqid(),
            'is_active' => true,
        ]);
    }

    protected function makeTeacher(string $name): Teacher
    {
        return Teacher::create(['first_name' => $name, 'last_name' => 'Учитель', 'is_active' => true]);
    }

    protected function makeLesson(
        SchoolClass $class,
        Subject $subject,
        Teacher $teacher,
        Day $day,
        Period $period,
    ): Timetable {
        return Timetable::create([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'day_id' => $day->id,
            'period_id' => $period->id,
        ]);
    }

    public function test_route_is_dashboard_native_and_does_not_restore_the_legacy_namespace(): void
    {
        $this->assertSame('/dashboard/school-timetable', route('dashboard.school-timetable.index', absolute: false));
        $this->assertFalse(Route::has('dashboard.timetable.index'));
        $this->assertStringNotContainsString('/admin', route('dashboard.school-timetable.index'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard.school-timetable.index'))->assertRedirect(route('login'));
    }

    public function test_teacher_only_user_is_redirected_to_the_teacher_portal(): void
    {
        $this->actingAs($this->teacherOnlyUser())
            ->get(route('dashboard.school-timetable.index'))
            ->assertRedirect(route('filament.teacher.pages.dashboard'));
    }

    public function test_administrative_user_without_timetable_permission_is_forbidden(): void
    {
        $this->actingAs($this->administrativeUser())
            ->get(route('dashboard.school-timetable.index'))
            ->assertForbidden();
    }

    public function test_view_only_user_can_open_the_dashboard_shell(): void
    {
        $response = $this->actingAs($this->administrativeUser('view timetable'))
            ->get(route('dashboard.school-timetable.index'));

        $response->assertOk()->assertSee('data-shell', false);
        $response->assertSee(route('dashboard.index'), false);
    }

    public function test_manage_only_user_can_open_the_read_only_view(): void
    {
        $this->actingAs($this->administrativeUser('manage timetable'))
            ->get(route('dashboard.school-timetable.index'))
            ->assertOk();
    }

    public function test_sidebar_entry_is_visible_only_with_a_timetable_permission(): void
    {
        $route = route('dashboard.school-timetable.index');

        $allowed = (string) $this->actingAs($this->administrativeUser('view timetable'))
            ->view('layouts.partials.shell-sidebar');
        $this->assertStringContainsString($route, $allowed);

        $denied = (string) $this->actingAs($this->administrativeUser())
            ->view('layouts.partials.shell-sidebar');
        $this->assertStringNotContainsString($route, $denied);
    }

    public function test_default_view_shows_all_active_classes_but_not_inactive_classes(): void
    {
        $first = $this->makeClass('Первый класс');
        $second = $this->makeClass('Второй класс');
        $inactive = $this->makeClass('Закрытый класс', false);

        $response = $this->actingAs($this->administrativeUser('view timetable'))
            ->get(route('dashboard.school-timetable.index'));

        $response->assertOk();
        $response->assertSee('data-school-class-id="'.$first->id.'"', false);
        $response->assertSee('data-school-class-id="'.$second->id.'"', false);
        $response->assertDontSee('data-school-class-id="'.$inactive->id.'"', false);
    }

    public function test_one_class_filter_excludes_other_classes_and_persists_selection(): void
    {
        $selected = $this->makeClass('Выбранный класс');
        $other = $this->makeClass('Другой класс');

        $response = $this->actingAs($this->administrativeUser('view timetable'))
            ->get(route('dashboard.school-timetable.index', ['classes' => [$selected->id]]));

        $response->assertOk();
        $response->assertSee('data-school-class-id="'.$selected->id.'"', false);
        $response->assertDontSee('data-school-class-id="'.$other->id.'"', false);
        $response->assertSee('value="'.$selected->id.'" selected', false);
    }

    public function test_two_class_filter_includes_both_and_excludes_a_third(): void
    {
        $first = $this->makeClass('Класс А');
        $second = $this->makeClass('Класс Б');
        $third = $this->makeClass('Класс В');

        $response = $this->actingAs($this->administrativeUser('view timetable'))
            ->get(route('dashboard.school-timetable.index', ['classes' => [$first->id, $second->id]]));

        $response->assertSee('data-school-class-id="'.$first->id.'"', false);
        $response->assertSee('data-school-class-id="'.$second->id.'"', false);
        $response->assertDontSee('data-school-class-id="'.$third->id.'"', false);
    }

    public function test_reset_url_without_filter_returns_to_all_classes(): void
    {
        $first = $this->makeClass('Класс один');
        $second = $this->makeClass('Класс два');

        $response = $this->actingAs($this->administrativeUser('view timetable'))
            ->get(route('dashboard.school-timetable.index'));

        $response->assertSee('data-school-class-id="'.$first->id.'"', false);
        $response->assertSee('data-school-class-id="'.$second->id.'"', false);
        $response->assertSee('href="'.route('dashboard.school-timetable.index').'"', false);
    }

    public function test_invalid_nonexistent_and_inactive_class_ids_are_rejected_safely(): void
    {
        $inactive = $this->makeClass('Неактивный', false);
        $user = $this->administrativeUser('view timetable');

        $this->actingAs($user)
            ->from(route('dashboard.school-timetable.index'))
            ->get(route('dashboard.school-timetable.index', ['classes' => [999999]]))
            ->assertRedirect(route('dashboard.school-timetable.index'))
            ->assertSessionHasErrors('classes.0');

        $this->actingAs($user)
            ->from(route('dashboard.school-timetable.index'))
            ->get(route('dashboard.school-timetable.index', ['classes' => [$inactive->id]]))
            ->assertRedirect(route('dashboard.school-timetable.index'))
            ->assertSessionHasErrors('classes.0');
    }

    public function test_duplicate_filter_ids_render_only_one_class_card(): void
    {
        $class = $this->makeClass('Без дублей');

        $response = $this->actingAs($this->administrativeUser('view timetable'))
            ->get(route('dashboard.school-timetable.index', ['classes' => [$class->id, $class->id]]));

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'data-school-class-id="'.$class->id.'"'));
    }

    public function test_canonical_timetable_subject_teacher_and_edit_link_are_rendered(): void
    {
        $class = $this->makeClass('Канонический класс');
        $day = $this->makeDay('sun', 1, 'Воскресенье');
        $period = $this->makePeriod(1);
        $subject = $this->makeSubject('Математика');
        $teacher = $this->makeTeacher('Анна');
        $lesson = $this->makeLesson($class, $subject, $teacher, $day, $period);

        $response = $this->actingAs($this->administrativeUser('view timetable'))
            ->get(route('dashboard.school-timetable.index', ['classes' => [$class->id]]));

        $response->assertSee($subject->name_ru);
        $response->assertSee($teacher->full_name);
        $response->assertSee('data-timetable-lesson-id="'.$lesson->id.'"', false);
        $response->assertSee(route('dashboard.classes.timetable', $class), false);
        $this->assertFalse(Route::has('dashboard.school-timetable.store'));
    }

    public function test_only_working_days_render_and_periods_are_ordered_by_number(): void
    {
        $this->makeClass('Рабочие дни');
        $this->makeDay('thu', 4, 'Четверг');
        $this->makeDay('fri', 5, 'Пятница');
        $this->makeDay('sun', 0, 'Воскресенье');
        $this->makePeriod(2);
        $this->makePeriod(1);
        TimetableSetting::updateOrCreate(['id' => 1], ['non_working_days' => ['fri']]);

        $response = $this->actingAs($this->administrativeUser('view timetable'))
            ->get(route('dashboard.school-timetable.index'));

        $response->assertSee('Воскресенье')->assertSee('Четверг')->assertDontSee('Пятница');
        $response->assertSeeInOrder(['>1</td>', '>2</td>'], false);
    }

    public function test_empty_class_and_empty_school_render_cleanly(): void
    {
        $this->makeClass('Пустой класс');
        $this->makeDay();
        $this->makePeriod(1);
        $user = $this->administrativeUser('view timetable');

        $this->actingAs($user)
            ->get(route('dashboard.school-timetable.index'))
            ->assertOk()
            ->assertSee(__('timetable.no_lessons_yet'))
            ->assertSee('aria-label="'.__('timetable.empty_slot').'"', false);

        SchoolClass::query()->update(['is_active' => false]);

        $this->actingAs($user)
            ->get(route('dashboard.school-timetable.index'))
            ->assertOk()
            ->assertSee(__('timetable.no_active_classes'));
    }

    public function test_conflicts_are_visible_and_all_malformed_same_slot_rows_are_retained(): void
    {
        Schema::table('timetables', function (Blueprint $table) {
            $table->dropUnique(['class_id', 'day_id', 'period_id']);
            $table->dropUnique(['teacher_id', 'day_id', 'period_id']);
        });

        $selectedClass = $this->makeClass('Выбранный конфликтный класс');
        $unselectedClass = $this->makeClass('Скрытый конфликтный класс');
        $day = $this->makeDay();
        $period = $this->makePeriod(1);
        $teacher = $this->makeTeacher('Конфликтный');
        $subject = $this->makeSubject('Повторяемый предмет');
        $otherSubject = $this->makeSubject('Второй предмет');
        $otherTeacher = $this->makeTeacher('Другой');

        $first = $this->makeLesson($selectedClass, $subject, $teacher, $day, $period);
        $duplicate = $this->makeLesson($selectedClass, $subject, $teacher, $day, $period);
        $classConflict = $this->makeLesson($selectedClass, $otherSubject, $otherTeacher, $day, $period);
        $this->makeLesson($unselectedClass, $otherSubject, $teacher, $day, $period);

        $response = $this->actingAs($this->administrativeUser('view timetable'))
            ->get(route('dashboard.school-timetable.index', ['classes' => [$selectedClass->id]]));

        $response->assertOk();
        $response->assertSee(__('timetable.teacher_conflict_badge'));
        $response->assertSee(__('timetable.class_conflict_badge'));
        $response->assertSee(__('timetable.duplicate_cell_badge'));
        $response->assertDontSee('data-school-class-id="'.$unselectedClass->id.'"', false);
        foreach ([$first, $duplicate, $classConflict] as $lesson) {
            $response->assertSee('data-timetable-lesson-id="'.$lesson->id.'"', false);
        }
        $this->assertSame(2, substr_count($response->getContent(), $subject->name_ru));
    }
}
