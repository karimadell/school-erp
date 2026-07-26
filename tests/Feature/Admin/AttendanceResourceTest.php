<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Attendances\Pages\CreateAttendance;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Models\Attendance;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeEnrollment(): Enrollment
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A',
            'name_ar' => 'فصل A',
            'name_ru' => 'Класс A',
        ]);
        $student = Student::create(['name' => 'Test Student']);

        return Enrollment::create([
            'student_id' => $student->id,
            'stage_id' => $stage->id,
            'grade_id' => $grade->id,
            'class_id' => $class->id,
            'status' => 'active',
        ]);
    }

    /**
     * Regression test: attendances.attendance_key is NOT NULL and unique
     * with no default, but AttendanceForm never collected it and nothing
     * generated one — every Filament-admin attendance create failed with a
     * NOT NULL constraint violation before this fix.
     */
    public function test_daily_attendance_can_be_created_via_filament(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();

        Livewire::actingAs($user)
            ->test(CreateAttendance::class)
            ->fillForm([
                'enrollment_id' => $enrollment->id,
                'date' => '2026-01-15',
                'type' => 'daily',
                'status' => 'present',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('attendances', [
            'enrollment_id' => $enrollment->id,
            'type' => 'daily',
            'status' => 'present',
            'attendance_key' => "daily-{$enrollment->id}-2026-01-15",
        ]);
    }

    public function test_period_attendance_can_be_created_via_filament(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);

        Livewire::actingAs($user)
            ->test(CreateAttendance::class)
            ->fillForm([
                'enrollment_id' => $enrollment->id,
                'date' => '2026-01-15',
                'type' => 'period',
                'period_id' => $period->id,
                'status' => 'absent',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('attendances', [
            'enrollment_id' => $enrollment->id,
            'period_id' => $period->id,
            'type' => 'period',
            'status' => 'absent',
            'attendance_key' => "period-{$enrollment->id}-2026-01-15-{$period->id}",
        ]);
    }

    public function test_attendance_can_be_edited_via_filament(): void
    {
        $user = User::factory()->create();
        $enrollment = $this->makeEnrollment();

        $attendance = Attendance::create([
            'enrollment_id' => $enrollment->id,
            'date' => '2026-01-15',
            'type' => 'daily',
            'status' => 'present',
            'attendance_key' => Attendance::buildAttendanceKey('daily', $enrollment->id, '2026-01-15'),
        ]);

        Livewire::actingAs($user)
            ->test(EditAttendance::class, ['record' => $attendance->getKey()])
            ->fillForm([
                'enrollment_id' => $enrollment->id,
                'date' => '2026-01-15',
                'type' => 'daily',
                'status' => 'absent',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('absent', $attendance->fresh()->status);
    }

    /**
     * Regression test: AttendanceResource's navigation label, model
     * labels, and every form/table/infolist field label were hardcoded
     * English strings — this is the first localized Filament resource in
     * this codebase; nothing else in app/Filament/Resources previously
     * used __() at all.
     */
    public function test_resource_pages_render_without_untranslated_labels_in_all_locales(): void
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

        foreach (['ru', 'en', 'ar'] as $locale) {
            app()->setLocale($locale);

            Livewire::actingAs($user)
                ->test(ListAttendances::class)
                ->assertSuccessful()
                ->assertDontSee('attendance.', false);

            $create = Livewire::actingAs($user)
                ->test(CreateAttendance::class)
                ->assertSuccessful();

            $create->assertDontSee('attendance.', false);
            $create->assertSee(__('attendance.present'));
            $create->assertSee(__('attendance.daily'));

            // The old code hardcoded English option labels regardless of
            // locale ('Daily', 'Present', etc.) with zero __() calls, so a
            // plain 'attendance.' leak check above can't catch that — it
            // never leaked a raw key, it just always rendered English.
            // Confirm the hardcoded English text is actually gone once the
            // locale genuinely translates to something else.
            if ($locale !== 'en') {
                $create->assertDontSee('Present');
                $create->assertDontSee('Daily');
            }
        }
    }
}
