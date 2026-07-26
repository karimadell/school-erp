<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeEnrollment(string $studentName = 'Test Student'): Enrollment
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A',
            'name_ar' => 'فصل A',
            'name_ru' => 'Класс A',
        ]);
        $student = Student::forceCreate(['name' => $studentName]);

        return Enrollment::create([
            'student_id' => $student->id,
            'stage_id' => $stage->id,
            'grade_id' => $grade->id,
            'class_id' => $class->id,
            'status' => 'active',
        ]);
    }

    public function test_index_page_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard.attendance.index'))->assertOk();
    }

    /**
     * Regression test: no navigation link to any of the three report/
     * dashboard routes existed anywhere in the UI before this fix.
     */
    public function test_dashboard_nav_links_to_attendance_reports_and_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.attendance.index'));

        $response->assertOk();
        $response->assertSee(route('dashboard.attendance.reports.class'), false);
        $response->assertSee(route('dashboard.attendance.reports.student'), false);
        $response->assertSee(route('dashboard.attendance.dashboard'), false);
    }

    public function test_take_page_renders_with_class_students_and_periods(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();
        Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);

        $response = $this->actingAs($user)->get(route('dashboard.attendance.create', [
            'class_id' => $enrollment->class_id,
            'date' => '2026-01-15',
            'type' => 'daily',
        ]));

        $response->assertOk();
    }

    public function test_daily_attendance_can_be_stored_via_dashboard(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();

        $response = $this->actingAs($user)->post(route('dashboard.attendance.store'), [
            'class_id' => $enrollment->class_id,
            'date' => '2026-01-15',
            'type' => 'daily',
            'attendance' => [
                $enrollment->id => ['status' => 'present'],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('attendances', [
            'enrollment_id' => $enrollment->id,
            'status' => 'present',
        ]);
    }

    public function test_all_supported_daily_statuses_can_be_stored(): void
    {
        $user = User::factory()->create();

        foreach (['present', 'absent', 'late', 'excused'] as $status) {
            $enrollment = $this->makeEnrollment("Student {$status}");

            $response = $this->actingAs($user)->post(route('dashboard.attendance.store'), [
                'class_id' => $enrollment->class_id,
                'date' => '2026-01-15',
                'type' => 'daily',
                'attendance' => [
                    $enrollment->id => ['status' => $status],
                ],
            ]);

            $response->assertRedirect();
            $this->assertDatabaseHas('attendances', [
                'enrollment_id' => $enrollment->id,
                'status' => $status,
            ]);
        }
    }

    public function test_valid_period_status_can_be_stored(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);

        $response = $this->actingAs($user)->post(route('dashboard.attendance.store'), [
            'class_id' => $enrollment->class_id,
            'date' => '2026-01-15',
            'type' => 'period',
            'attendance' => [
                $enrollment->id => [$period->id => 'late'],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('attendances', [
            'enrollment_id' => $enrollment->id,
            'period_id' => $period->id,
            'status' => 'late',
        ]);
    }

    /**
     * Regression test: AttendanceController::store() validated class_id/
     * date/type/attendance (as an array) but never validated the nested
     * status values themselves, so any string could be persisted.
     */
    public function test_invalid_daily_status_is_rejected(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();

        $response = $this->actingAs($user)->post(route('dashboard.attendance.store'), [
            'class_id' => $enrollment->class_id,
            'date' => '2026-01-15',
            'type' => 'daily',
            'attendance' => [
                $enrollment->id => ['status' => 'bogus-status'],
            ],
        ]);

        $response->assertSessionHasErrors('attendance.' . $enrollment->id . '.status');
        $this->assertDatabaseMissing('attendances', ['enrollment_id' => $enrollment->id]);
    }

    public function test_invalid_period_status_is_rejected(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);

        $response = $this->actingAs($user)->post(route('dashboard.attendance.store'), [
            'class_id' => $enrollment->class_id,
            'date' => '2026-01-15',
            'type' => 'period',
            'attendance' => [
                $enrollment->id => [$period->id => 'not-a-real-status'],
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('attendances', ['enrollment_id' => $enrollment->id]);
    }

    /**
     * Regression test: take.blade.php used
     * __('attendance.no_students') ?? 'Нет учеников в этом классе' — a
     * dead fallback (__() never returns null) that always rendered the
     * hardcoded Russian text regardless of locale.
     */
    public function test_take_page_renders_without_fallback_expressions_or_translation_leaks(): void
    {
        $user = User::factory()->create();
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A',
            'name_ar' => 'فصل A',
            'name_ru' => 'Класс A',
        ]);

        foreach (['ru', 'en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $response = $this->actingAs($user)->get(route('dashboard.attendance.create', [
                'class_id' => $class->id,
                'date' => '2026-01-15',
                'type' => 'daily',
            ]));

            $response->assertOk();
            $response->assertDontSee('attendance.', false);

            if ($locale !== 'ru') {
                $response->assertDontSee('Нет учеников в этом классе');
                $response->assertDontSee('Назад');
            }
        }
    }

    public function test_class_report_page_renders(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();

        $response = $this->actingAs($user)->get(route('dashboard.attendance.reports.class', [
            'class_id' => $enrollment->class_id,
        ]));

        $response->assertOk();
    }

    /**
     * Regression test: dashboard.attendance.reports.student was missing
     * entirely, so this route 500'd with a ViewNotFoundException before
     * the view was added.
     */
    public function test_student_report_page_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard.attendance.reports.student'))->assertOk();
    }

    public function test_student_report_page_renders_with_data(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment('Ivan Ivanov');

        Attendance::create([
            'enrollment_id' => $enrollment->id,
            'date' => '2026-01-15',
            'type' => 'daily',
            'status' => 'present',
            'attendance_key' => Attendance::buildAttendanceKey('daily', $enrollment->id, '2026-01-15'),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.attendance.reports.student'));

        $response->assertOk();
        $response->assertSee('Ivan Ivanov');
    }

    /**
     * Regression test: dashboard.attendance.dashboard was missing
     * entirely, so this route 500'd with a ViewNotFoundException before
     * the view was added.
     */
    public function test_attendance_dashboard_page_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard.attendance.dashboard'))->assertOk();
    }

    public function test_attendance_dashboard_page_renders_with_data(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();

        Attendance::create([
            'enrollment_id' => $enrollment->id,
            'date' => '2026-01-15',
            'type' => 'daily',
            'status' => 'present',
            'attendance_key' => Attendance::buildAttendanceKey('daily', $enrollment->id, '2026-01-15'),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.attendance.dashboard'));

        $response->assertOk();
    }

    /**
     * Regression test: attendance.php was missing 14 keys in ar/en (12 in
     * ru) that the attendance views already referenced, and ar/en had no
     * timetable.php at all despite take.blade.php calling
     * __('timetable.lesson') — both rendered as literal untranslated key
     * strings before this fix.
     */
    public function test_attendance_pages_render_without_translation_key_leaks_in_all_locales(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();
        Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);

        Attendance::create([
            'enrollment_id' => $enrollment->id,
            'date' => '2026-01-15',
            'type' => 'daily',
            'status' => 'present',
            'attendance_key' => Attendance::buildAttendanceKey('daily', $enrollment->id, '2026-01-15'),
        ]);

        foreach (['ru', 'en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $index = $this->actingAs($user)->get(route('dashboard.attendance.index'));
            $index->assertOk();
            $index->assertDontSee('attendance.', false);

            $take = $this->actingAs($user)->get(route('dashboard.attendance.create', [
                'class_id' => $enrollment->class_id,
                'date' => '2026-01-15',
                'type' => 'daily',
            ]));
            $take->assertOk();
            $take->assertDontSee('attendance.', false);
            $take->assertDontSee('timetable.', false);

            $classReport = $this->actingAs($user)->get(route('dashboard.attendance.reports.class', [
                'class_id' => $enrollment->class_id,
            ]));
            $classReport->assertOk();
            $classReport->assertDontSee('attendance.', false);

            $studentReport = $this->actingAs($user)->get(route('dashboard.attendance.reports.student'));
            $studentReport->assertOk();
            $studentReport->assertDontSee('attendance.', false);

            $dashboard = $this->actingAs($user)->get(route('dashboard.attendance.dashboard'));
            $dashboard->assertOk();
            $dashboard->assertDontSee('attendance.', false);
        }
    }
}
