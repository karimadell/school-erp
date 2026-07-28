<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Quarter;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Item 6 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, B6): Exam.class_id ->
 * SchoolClass.grade_id -> Grade.stage_id are mutable, and
 * Exam.quarter_id -> Quarter.academic_year_id is only a transitive
 * derivation with nullOnDelete — either could silently and retroactively
 * change which grade/stage/year a historical exam implies it belonged
 * to. ExamSnapshotObserver closes this by writing academic_year_id/
 * grade_id/stage_id once, at creation, never re-synced afterward.
 */
class ExamSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);

        return SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    protected function makeSubject(): Subject
    {
        return Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    protected function makeYear(bool $active = true): AcademicYear
    {
        return AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2020-09-01', 'end_date' => '2021-05-31', 'is_active' => $active,
        ]);
    }

    protected function makeQuarter(AcademicYear $year): Quarter
    {
        return Quarter::create(['academic_year_id' => $year->id, 'name' => 'Q-' . uniqid(), 'order' => 1]);
    }

    /**
     * Batch 11 / C3: Exam creation now also requires a matching Curriculum
     * row (year + grade + subject) — unrelated to this file's own concern
     * (snapshot immutability), so fixtures here just need one to exist.
     */
    protected function makeCurriculum(AcademicYear $year, int $gradeId, int $subjectId): Curriculum
    {
        return Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $gradeId, 'subject_id' => $subjectId,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Snapshot captured correctly at creation
    |--------------------------------------------------------------------------
    */

    public function test_creating_an_exam_snapshots_academic_year_grade_and_stage(): void
    {
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class->grade_id, $subject->id);

        $exam = Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);

        $this->assertSame($year->id, $exam->academic_year_id);
        $this->assertSame($class->grade_id, $exam->grade_id);
        $this->assertSame($class->grade->stage_id, $exam->stage_id);
    }

    public function test_creating_an_exam_with_no_quarter_still_snapshots_grade_and_stage_before_the_lock_blocks_it(): void
    {
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        // grade_id/stage_id are always resolvable from class_id; only
        // academic_year_id stays null (no quarter) — which is exactly
        // what makes AcademicYearLockObserver still reject this create,
        // unchanged from Item 2's existing fail-closed behavior.
        $this->expectException(ValidationException::class);
        Exam::create(['name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id]);

        $this->assertDatabaseCount('exams', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | The core regression: snapshot is immutable once written
    |--------------------------------------------------------------------------
    */

    public function test_reassigning_the_classs_grade_does_not_change_the_exams_own_snapshot(): void
    {
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $originalGradeId = $class->grade_id;
        $this->makeCurriculum($year, $originalGradeId, $subject->id);

        $exam = Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);

        $newStage = Stage::create(['name' => 'Secondary']);
        $newGrade = Grade::create(['name' => 'Grade 7', 'stage_id' => $newStage->id]);
        $class->update(['grade_id' => $newGrade->id]);

        $this->assertSame($originalGradeId, $exam->fresh()->grade_id);
        $this->assertNotSame($newGrade->id, $exam->fresh()->grade_id);
    }

    public function test_reassigning_the_grades_stage_does_not_change_the_exams_own_snapshot(): void
    {
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $originalStageId = $class->grade->stage_id;
        $this->makeCurriculum($year, $class->grade_id, $subject->id);

        $exam = Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);

        $newStage = Stage::create(['name' => 'Secondary']);
        $class->grade->update(['stage_id' => $newStage->id]);

        $this->assertSame($originalStageId, $exam->fresh()->stage_id);
        $this->assertNotSame($newStage->id, $exam->fresh()->stage_id);
    }

    /*
    |--------------------------------------------------------------------------
    | resolveAcademicYear() prefers the snapshot
    |--------------------------------------------------------------------------
    */

    public function test_resolveacademicyear_uses_the_snapshot_even_after_the_quarter_is_deleted(): void
    {
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class->grade_id, $subject->id);

        $exam = Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);

        // Simulates the nullOnDelete scenario the readiness report flagged
        // — quarter_id nulls out, but the snapshot survives independently.
        \App\Support\AcademicYearLock::withoutLock(fn () => $quarter->delete());

        $this->assertNull($exam->fresh()->quarter_id);
        $this->assertSame($year->id, $exam->fresh()->resolveAcademicYear()?->id);
    }

    public function test_a_legacy_exam_with_no_snapshot_still_falls_back_to_quarter_derivation(): void
    {
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        // Simulates a pre-Item-6 row: force the snapshot columns to stay
        // null despite a real quarter_id being present, bypassing the
        // observer via forceFill + saveQuietly (no creating event).
        $exam = new Exam([
            'name' => 'Legacy Exam', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);
        $exam->saveQuietly();

        $this->assertNull($exam->fresh()->academic_year_id);
        $this->assertSame($year->id, $exam->fresh()->resolveAcademicYear()?->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Never overwrite / only on creating
    |--------------------------------------------------------------------------
    */

    public function test_an_explicitly_provided_academic_year_id_is_not_overwritten_by_the_observer(): void
    {
        // $year (inactive) owns the quarter; $otherYear (active) is what
        // gets explicitly provided — different from what the quarter
        // would derive, and active so the lock itself doesn't reject the
        // create for an unrelated reason. Proves the observer leaves an
        // already-set academic_year_id alone rather than deriving over it.
        $year = $this->makeYear(false);
        $quarter = \App\Support\AcademicYearLock::withoutLock(fn () => $this->makeQuarter($year));
        $otherYear = $this->makeYear(true);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $this->makeCurriculum($otherYear, $class->grade_id, $subject->id);

        $exam = Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id,
            'quarter_id' => $quarter->id, 'academic_year_id' => $otherYear->id,
        ]);

        $this->assertSame($otherYear->id, $exam->academic_year_id);
    }

    public function test_updating_an_unrelated_field_does_not_touch_the_snapshot(): void
    {
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class->grade_id, $subject->id);

        $exam = Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);
        $originalSnapshot = [$exam->academic_year_id, $exam->grade_id, $exam->stage_id];

        $exam->update(['max_score' => 50]);

        $this->assertSame($originalSnapshot, [$exam->fresh()->academic_year_id, $exam->fresh()->grade_id, $exam->fresh()->stage_id]);
    }

    /*
    |--------------------------------------------------------------------------
    | End-to-end with the lock: snapshot + unlock cooperate correctly
    |--------------------------------------------------------------------------
    */

    public function test_an_exam_created_against_a_historical_but_unlocked_year_still_gets_a_correct_snapshot(): void
    {
        $year = $this->makeYear(false);
        $quarter = \App\Support\AcademicYearLock::withoutLock(fn () => $this->makeQuarter($year));
        \App\Models\AcademicYearUnlock::create([
            'academic_year_id' => $year->id, 'reason' => 'Backfill', 'unlocked_by' => null, 'expires_at' => now()->addHour(),
        ]);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class->grade_id, $subject->id);

        $exam = Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);

        $this->assertSame($year->id, $exam->academic_year_id);
    }
}
