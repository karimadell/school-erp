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
}
