<?php

namespace Tests\Feature;

use App\Models\BellSchedule;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use Database\Seeders\UatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UatTimetableFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_uat_timetable_fixture_is_idempotent_relationally_valid_and_conflict_free(): void
    {
        $this->enableUatSeeding();

        try {
            $this->seed(UatSeeder::class);
            $this->seed(UatSeeder::class);

            $version = TimetableVersion::where('name', 'UAT — Расписание по образцу 2025/26')->firstOrFail();
            $schedule = BellSchedule::where('name', 'UAT — Расписание звонков 09:00')->firstOrFail();
            $entries = $version->entries()->with([
                'period', 'schoolClass', 'subject', 'teacherAssignment.teacher', 'classroom', 'curriculum',
            ])->get();

            $this->assertCount(24, $entries);
            $this->assertDatabaseCount('teachers', 2);
            $this->assertDatabaseCount('teacher_assignments', 15);
            $this->assertDatabaseCount('teacher_salaries', 1);

            $this->assertSame([
                1 => ['09:00', '09:40'],
                2 => ['09:50', '10:30'],
                3 => ['10:35', '11:15'],
                4 => ['11:20', '12:00'],
                5 => ['12:15', '12:55'],
                6 => ['13:10', '13:50'],
            ], $schedule->periods->mapWithKeys(fn ($period) => [
                $period->period_number => [substr($period->starts_at, 0, 5), substr($period->ends_at, 0, 5)],
            ])->all());

            foreach ($entries as $entry) {
                $this->assertNotNull($entry->period);
                $this->assertNotNull($entry->schoolClass);
                $this->assertNotNull($entry->subject);
                $this->assertNotNull($entry->teacherAssignment?->teacher);
                $this->assertNotNull($entry->classroom);
                $this->assertNotNull($entry->curriculum);
                $this->assertSame($entry->class_id, $entry->teacherAssignment->class_id);
                $this->assertSame($entry->subject_id, $entry->teacherAssignment->subject_id);
                $this->assertSame($entry->subject_id, $entry->curriculum->subject_id);
            }

            $firstClass = SchoolClass::where('code', 'UAT-A')->firstOrFail();
            $sundayFirstLesson = TimetableEntry::where('timetable_version_id', $version->id)
                ->where('class_id', $firstClass->id)->where('weekday', 7)
                ->whereHas('period', fn ($query) => $query->where('period_number', 1))
                ->with('subject')->firstOrFail();
            $this->assertSame('Русский язык', $sundayFirstLesson->subject->name_ru);

            foreach (['class_id', 'teacher_assignment_id', 'classroom_id'] as $conflictDimension) {
                $conflicts = $entries
                    ->groupBy(fn (TimetableEntry $entry) => implode(':', [
                        $entry->weekday,
                        $entry->bell_schedule_period_id,
                        $entry->{$conflictDimension},
                    ]))
                    ->filter(fn ($slot) => $slot->count() > 1);

                $this->assertCount(0, $conflicts, "UAT fixture has a {$conflictDimension} timetable conflict.");
            }
        } finally {
            $this->setEnvironment('testing');
        }
    }

    public function test_uat_school_class_structure_is_complete_idempotent_and_available_to_enrollment(): void
    {
        $this->enableUatSeeding();

        try {
            $this->seed(UatSeeder::class);
            $originalIds = SchoolClass::query()
                ->where('code', 'like', 'UAT-%')
                ->pluck('id', 'code')
                ->all();

            $this->seed(UatSeeder::class);

            $classes = SchoolClass::query()
                ->where('code', 'like', 'UAT-%')
                ->with('grade.stage')
                ->get();

            $this->assertCount(12, $classes);
            $this->assertSame($originalIds, $classes->pluck('id', 'code')->all());
            $this->assertSame(range(0, 11), $classes->pluck('grade.level')->sort()->values()->all());

            foreach (range(0, 11) as $level) {
                $class = $classes->first(fn (SchoolClass $candidate) => $candidate->grade->level === $level);
                $this->assertNotNull($class);
                $this->assertSame("UAT — {$level} класс", $class->name_ru);
                $this->assertTrue($class->is_active);
                $this->assertSame(match (true) {
                    $level === 0 => 'UAT — Подготовительная группа',
                    $level <= 4 => 'UAT — Начальная школа',
                    default => 'UAT — Основная и старшая школа',
                }, $class->grade->stage->name);
            }

            $admin = User::where('email', 'admin.uat@school.test')->firstOrFail();
            $student = Student::where('email', 'student01.uat@example.invalid')->firstOrFail();
            $response = $this->actingAs($admin)
                ->get(route('dashboard.enrollments.create', $student))
                ->assertOk();

            $this->assertSame(12, substr_count($response->getContent(), 'data-grade='));
            foreach (range(0, 11) as $level) {
                $response->assertSee("UAT — {$level} класс");
            }
        } finally {
            $this->setEnvironment('testing');
        }
    }

    private function enableUatSeeding(): void
    {
        $this->setEnvironment('uat');
        config()->set('uat.passwords', [
            'admin' => 'admin-test-secret-123',
            'accountant' => 'accountant-test-secret-123',
            'cashier' => 'cashier-test-secret-123',
            'reception' => 'reception-test-secret-123',
        ]);
    }

    private function setEnvironment(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        config()->set('app.env', $environment);
    }
}
