<?php

namespace Tests\Feature;

use App\Filament\Resources\TimetableVersions\TimetableVersionResource;
use App\Models\Academic\Timetable as AcademicTimetable;
use App\Models\AcademicYear;
use App\Models\BellSchedule;
use App\Models\BellSchedulePeriod;
use App\Models\Curriculum;
use App\Models\Grade;
use App\Models\PhysicalClassroom;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Services\TimetableEntryService;
use App\Services\TimetableVersionService;
use App\Support\AcademicYearLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TimetableEntryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private Grade $grade;

    private SchoolClass $class;

    private Subject $subject;

    private Teacher $teacher;

    private TeacherAssignment $assignment;

    private BellSchedule $schedule;

    private BellSchedulePeriod $period;

    private BellSchedulePeriod $secondPeriod;

    private PhysicalClassroom $classroom;

    private TimetableVersion $version;

    private AcademicTimetable $header;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01',
            'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);
        $this->grade = Grade::create(['name' => 'Grade 5', 'stage_id' => $stage->id]);
        $this->class = $this->makeClass('5A');
        $this->subject = $this->makeSubject('MATH', 'Математика');
        $this->makeCurriculum($this->subject);
        $this->teacher = $this->makeTeacher('Иванов');
        $this->assignment = $this->makeAssignment($this->teacher, $this->class, $this->subject);
        $this->schedule = BellSchedule::create([
            'academic_year_id' => $this->year->id, 'name' => 'Обычное', 'shift' => 1,
            'is_default' => true, 'is_active' => true,
        ]);
        $this->period = $this->makePeriod($this->schedule, 1, '08:00', '08:45');
        $this->secondPeriod = $this->makePeriod($this->schedule, 2, '08:55', '09:40');
        $this->classroom = $this->makeClassroom('R-101');
        $this->version = TimetableVersion::create([
            'academic_year_id' => $this->year->id, 'name' => 'Основное расписание',
            'effective_from' => '2026-09-01', 'effective_to' => '2027-06-30',
        ]);
        $this->header = AcademicTimetable::create([
            'timetable_version_id' => $this->version->id, 'name' => $this->version->name,
        ]);
    }

    public function test_valid_draft_entry_is_created_and_curriculum_is_derived(): void
    {
        $entry = $this->createEntry();

        $this->assertTrue($entry->version->is($this->version));
        $this->assertSame($this->subject->id, $entry->curriculum->subject_id);
        $this->assertSame($this->class->grade_id, $entry->curriculum->grade_id);
    }

    public function test_published_version_rejects_entry_writes(): void
    {
        $entry = $this->createEntry();
        app(TimetableVersionService::class)->publish($this->version, User::factory()->create());

        foreach ([
            fn () => $this->createEntry(['weekday' => 2]),
            fn () => app(TimetableEntryService::class)->update($entry->fresh(), ['weekday' => 2]),
            fn () => app(TimetableEntryService::class)->delete($entry->fresh()),
        ] as $write) {
            try {
                $write();
                $this->fail('Expected published timetable protection.');
            } catch (ValidationException) {
                $this->assertDatabaseHas('timetable_entries', ['id' => $entry->id, 'weekday' => 1]);
            }
        }
    }

    public function test_inactive_classroom_is_rejected(): void
    {
        $room = $this->makeClassroom('R-102', false);
        $this->expectValidationKey('classroom_id', fn () => $this->createEntry(['classroom_id' => $room->id]));
    }

    public function test_cross_year_classroom_is_rejected(): void
    {
        $otherYear = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01',
            'end_date' => '2026-06-30', 'is_active' => false,
        ]);
        $room = AcademicYearLock::withoutLock(fn () => PhysicalClassroom::create([
            'academic_year_id' => $otherYear->id, 'code' => 'OLD-1', 'name' => 'Old room',
            'capacity' => 20, 'room_type' => PhysicalClassroom::TYPE_CLASSROOM, 'is_active' => true,
        ]));

        $this->expectValidationKey('classroom_id', fn () => $this->createEntry(['classroom_id' => $room->id]));
    }

    public function test_period_from_another_bell_schedule_is_rejected(): void
    {
        $other = BellSchedule::create([
            'academic_year_id' => $this->year->id, 'name' => 'Другое', 'shift' => 1,
            'is_default' => false, 'is_active' => true,
        ]);
        $period = $this->makePeriod($other, 1, '10:00', '10:45');

        $this->expectValidationKey('bell_schedule_period_id', fn () => $this->createEntry([
            'bell_schedule_period_id' => $period->id,
        ]));
    }

    public function test_teacher_conflict_is_rejected(): void
    {
        $this->createEntry();
        $otherClass = $this->makeClass('5B');
        $assignment = $this->makeAssignment($this->teacher, $otherClass, $this->subject);

        $this->expectValidationKey('teacher_assignment_id', fn () => $this->createEntry([
            'class_id' => $otherClass->id, 'teacher_assignment_id' => $assignment->id,
            'classroom_id' => $this->makeClassroom('R-102')->id,
        ]));
    }

    public function test_class_conflict_is_rejected(): void
    {
        $this->createEntry();
        $subject = $this->makeSubject('SCI', 'Наука');
        $this->makeCurriculum($subject);
        $assignment = $this->makeAssignment($this->makeTeacher('Петров'), $this->class, $subject);

        $this->expectValidationKey('class_id', fn () => $this->createEntry([
            'subject_id' => $subject->id, 'teacher_assignment_id' => $assignment->id,
            'classroom_id' => $this->makeClassroom('R-102')->id,
        ]));
    }

    public function test_classroom_conflict_is_rejected(): void
    {
        $this->createEntry();
        $otherClass = $this->makeClass('5B');
        $assignment = $this->makeAssignment($this->makeTeacher('Петров'), $otherClass, $this->subject);

        $this->expectValidationKey('classroom_id', fn () => $this->createEntry([
            'class_id' => $otherClass->id, 'teacher_assignment_id' => $assignment->id,
        ]));
    }

    public function test_duplicate_entry_is_rejected_before_other_conflicts(): void
    {
        $this->createEntry();
        $this->expectValidationKey('entry', fn () => $this->createEntry());
    }

    public function test_direct_model_writes_use_the_same_locked_conflict_boundary(): void
    {
        $this->createEntry();

        $this->expectValidationKey('entry', fn () => TimetableEntry::create($this->entryAttributes()));
        $this->assertCount(1, TimetableEntry::all());
    }

    public function test_non_conflicting_entries_are_allowed(): void
    {
        $first = $this->createEntry();
        $second = $this->createEntry(['bell_schedule_period_id' => $this->secondPeriod->id]);
        $third = $this->createEntry(['weekday' => 2]);

        $this->assertCount(3, TimetableEntry::all());
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($second->id, $third->id);
    }

    public function test_filament_permission_and_legacy_model_boundaries_remain_intact(): void
    {
        $user = User::factory()->create();
        Permission::create(['name' => 'view timetable']);
        Permission::create(['name' => 'manage timetable']);
        $this->actingAs($user);
        $this->assertFalse(TimetableVersionResource::canViewAny());
        $user->givePermissionTo('view timetable');
        $this->assertTrue(TimetableVersionResource::canViewAny());
        $this->assertFalse(TimetableVersionResource::canEdit($this->version));
        $user->givePermissionTo('manage timetable');
        $this->assertTrue(TimetableVersionResource::canEdit($this->version));

        $this->assertSame('timetables', (new Timetable)->getTable());
        $this->assertSame('academic_timetables', (new AcademicTimetable)->getTable());
    }

    private function createEntry(array $overrides = []): TimetableEntry
    {
        return app(TimetableEntryService::class)->create($this->entryAttributes($overrides));
    }

    private function entryAttributes(array $overrides = []): array
    {
        return array_merge([
            'timetable_version_id' => $this->version->id,
            'academic_timetable_id' => $this->header->id,
            'weekday' => 1,
            'bell_schedule_id' => $this->schedule->id,
            'bell_schedule_period_id' => $this->period->id,
            'classroom_id' => $this->classroom->id,
            'teacher_assignment_id' => $this->assignment->id,
            'subject_id' => $this->subject->id,
            'class_id' => $this->class->id,
        ], $overrides);
    }

    private function expectValidationKey(string $key, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected validation failure for {$key}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    private function makeClass(string $code): SchoolClass
    {
        return SchoolClass::create([
            'grade_id' => $this->grade->id, 'code' => $code,
            'name_ru' => $code, 'name_ar' => $code, 'capacity' => 30, 'is_active' => true,
        ]);
    }

    private function makeSubject(string $code, string $name): Subject
    {
        return Subject::create(['code' => $code, 'name_ru' => $name, 'name_ar' => $name, 'is_active' => true]);
    }

    private function makeCurriculum(Subject $subject): Curriculum
    {
        return Curriculum::create([
            'academic_year_id' => $this->year->id, 'grade_id' => $this->grade->id,
            'subject_id' => $subject->id, 'weekly_hours' => 5,
            'type' => Curriculum::TYPE_MANDATORY, 'is_active' => true,
        ]);
    }

    private function makeTeacher(string $lastName): Teacher
    {
        return Teacher::create(['first_name' => 'Иван', 'last_name' => $lastName, 'is_active' => true]);
    }

    private function makeAssignment(Teacher $teacher, SchoolClass $class, Subject $subject): TeacherAssignment
    {
        return TeacherAssignment::create([
            'academic_year_id' => $this->year->id, 'teacher_id' => $teacher->id,
            'class_id' => $class->id, 'subject_id' => $subject->id,
        ]);
    }

    private function makePeriod(BellSchedule $schedule, int $number, string $start, string $end): BellSchedulePeriod
    {
        return BellSchedulePeriod::create([
            'bell_schedule_id' => $schedule->id, 'period_number' => $number,
            'starts_at' => $start, 'ends_at' => $end, 'is_active' => true,
        ]);
    }

    private function makeClassroom(string $code, bool $active = true): PhysicalClassroom
    {
        return PhysicalClassroom::create([
            'academic_year_id' => $this->year->id, 'code' => $code, 'name' => $code,
            'capacity' => 30, 'room_type' => PhysicalClassroom::TYPE_CLASSROOM, 'is_active' => $active,
        ]);
    }
}
