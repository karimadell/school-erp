<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\Grade;
use App\Models\Quarter;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\Subject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * Regression coverage for A2 (docs/IMPLEMENTATION_READINESS_ROADMAP.md):
 * the unique index on student_grades (student_id, subject_id, exam_id,
 * quarter_id) never blocked duplicates when quarter_id was NULL, because
 * NULL is never considered equal to NULL in a unique index/constraint.
 */
class StudentGradesUniqueIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function makeExamFixture(): Exam
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['stage_id' => $stage->id, 'name' => 'Grade 1']);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A', 'name_ar' => 'الفصل أ']);
        $subject = Subject::create(['code' => 'MATH', 'name_ar' => 'رياضيات']);

        return Exam::create(['name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id]);
    }

    public function test_legacy_index_shape_allowed_duplicate_null_quarter_rows(): void
    {
        // Proves the original bug's mechanism: a unique index that includes
        // a nullable column does not stop two rows from sharing the same
        // NULL value in that column. This is the exact shape `student_grades`
        // had before this fix, reproduced here on a disposable table so it
        // doesn't depend on (and can't be confused with) the real table.
        Schema::create('legacy_shape_probe', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('exam_id');
            $table->unsignedBigInteger('quarter_id')->nullable();
            $table->unique(['student_id', 'subject_id', 'exam_id', 'quarter_id']);
        });

        try {
            DB::table('legacy_shape_probe')->insert(['student_id' => 1, 'subject_id' => 1, 'exam_id' => 1, 'quarter_id' => null]);
            DB::table('legacy_shape_probe')->insert(['student_id' => 1, 'subject_id' => 1, 'exam_id' => 1, 'quarter_id' => null]);

            $this->assertSame(2, DB::table('legacy_shape_probe')->count());
        } finally {
            Schema::dropIfExists('legacy_shape_probe');
        }
    }

    public function test_student_grades_unique_index_rejects_duplicate_null_quarter_rows(): void
    {
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student']);

        DB::table('student_grades')->insert([
            'student_id' => $student->id,
            'subject_id' => $exam->subject_id,
            'exam_id' => $exam->id,
            'quarter_id' => null,
            'score' => 90,
        ]);

        $this->expectException(QueryException::class);

        DB::table('student_grades')->insert([
            'student_id' => $student->id,
            'subject_id' => $exam->subject_id,
            'exam_id' => $exam->id,
            'quarter_id' => null,
            'score' => 95,
        ]);
    }

    public function test_student_grades_unique_index_still_rejects_duplicate_non_null_quarter_rows(): void
    {
        // No regression on the case the old index already handled correctly.
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student']);
        $quarter = Quarter::create(['name' => 'Q1', 'order' => 1]);

        DB::table('student_grades')->insert([
            'student_id' => $student->id,
            'subject_id' => $exam->subject_id,
            'exam_id' => $exam->id,
            'quarter_id' => $quarter->id,
            'score' => 90,
        ]);

        $this->expectException(QueryException::class);

        DB::table('student_grades')->insert([
            'student_id' => $student->id,
            'subject_id' => $exam->subject_id,
            'exam_id' => $exam->id,
            'quarter_id' => $quarter->id,
            'score' => 95,
        ]);
    }

    public function test_different_quarters_are_still_allowed_for_the_same_student_subject_exam(): void
    {
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student']);
        $quarter1 = Quarter::create(['name' => 'Q1', 'order' => 1]);
        $quarter2 = Quarter::create(['name' => 'Q2', 'order' => 2]);

        DB::table('student_grades')->insert([
            'student_id' => $student->id,
            'subject_id' => $exam->subject_id,
            'exam_id' => $exam->id,
            'quarter_id' => $quarter1->id,
            'score' => 90,
        ]);

        DB::table('student_grades')->insert([
            'student_id' => $student->id,
            'subject_id' => $exam->subject_id,
            'exam_id' => $exam->id,
            'quarter_id' => $quarter2->id,
            'score' => 85,
        ]);

        $this->assertSame(2, DB::table('student_grades')->count());
    }

    public function test_update_or_create_with_null_quarter_updates_instead_of_duplicating(): void
    {
        // Mirrors bulkStore()'s real usage pattern (StudentGradeController::bulkStore).
        $exam = $this->makeExamFixture();
        $student = Student::create(['name' => 'Test Student']);

        $attributes = [
            'student_id' => $student->id,
            'subject_id' => $exam->subject_id,
            'exam_id' => $exam->id,
            'quarter_id' => null,
        ];

        StudentGrade::updateOrCreate($attributes, ['score' => 90, 'note' => null]);
        StudentGrade::updateOrCreate($attributes, ['score' => 95, 'note' => null]);

        $this->assertDatabaseCount('student_grades', 1);
        $this->assertDatabaseHas('student_grades', [
            'student_id' => $student->id,
            'subject_id' => $exam->subject_id,
            'exam_id' => $exam->id,
            'quarter_id' => null,
            'score' => 95,
        ]);
    }
}
