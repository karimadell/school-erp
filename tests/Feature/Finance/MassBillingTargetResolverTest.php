<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\BillingBatch;
use App\Services\Finance\BillingTargetResolver;
use App\Support\AcademicYearLock;

class MassBillingTargetResolverTest extends MassBillingTestCase
{
    private function resolve(BillingBatch $batch): array
    {
        return app(BillingTargetResolver::class)->resolve($batch)->all();
    }

    public function test_targets_a_single_selected_class(): void
    {
        $a1 = $this->enrolledStudent($this->classA, suffix: 'A1');
        $a2 = $this->enrolledStudent($this->classA, suffix: 'A2');
        $this->enrolledStudent($this->classB, suffix: 'B1');

        $resolved = $this->resolve($this->makeBatch(classIds: [$this->classA->id]));

        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], $resolved);
    }

    public function test_targets_multiple_selected_classes(): void
    {
        $a1 = $this->enrolledStudent($this->classA, suffix: 'A1');
        $b1 = $this->enrolledStudent($this->classB, suffix: 'B1');

        $resolved = $this->resolve($this->makeBatch(classIds: [$this->classA->id, $this->classB->id]));

        $this->assertEqualsCanonicalizing([$a1->id, $b1->id], $resolved);
    }

    public function test_explicit_inclusion_adds_a_student_outside_the_selected_classes(): void
    {
        $a1 = $this->enrolledStudent($this->classA, suffix: 'A1');
        $outsider = $this->enrolledStudent($this->classB, suffix: 'B1');

        $resolved = $this->resolve($this->makeBatch(classIds: [$this->classA->id], include: [$outsider->id]));

        $this->assertEqualsCanonicalizing([$a1->id, $outsider->id], $resolved);
    }

    public function test_explicit_exclusion_removes_a_student_from_the_selected_classes(): void
    {
        $keep = $this->enrolledStudent($this->classA, suffix: 'A1');
        $drop = $this->enrolledStudent($this->classA, suffix: 'A2');

        $resolved = $this->resolve($this->makeBatch(classIds: [$this->classA->id], exclude: [$drop->id]));

        $this->assertSame([$keep->id], $resolved);
    }

    public function test_exclusion_wins_over_inclusion(): void
    {
        $conflicted = $this->enrolledStudent($this->classA, suffix: 'A1');

        $resolved = $this->resolve($this->makeBatch(
            classIds: [$this->classA->id],
            include: [$conflicted->id],
            exclude: [$conflicted->id],
        ));

        $this->assertSame([], $resolved);
    }

    public function test_a_student_selected_by_class_and_inclusion_is_deduplicated(): void
    {
        $student = $this->enrolledStudent($this->classA, suffix: 'A1');

        $resolved = $this->resolve($this->makeBatch(classIds: [$this->classA->id], include: [$student->id]));

        $this->assertSame([$student->id], $resolved);
    }

    public function test_resolution_uses_enrollment_class_not_stale_student_class_id(): void
    {
        // Stale students.class_id points at class A, but the year's enrollment
        // places the student in class B. Resolution must follow the enrollment.
        $student = $this->makeStudent('X');
        $student->forceFill(['class_id' => $this->classA->id])->save();
        $this->enroll($student, $this->classB);

        $this->assertSame([], $this->resolve($this->makeBatch(classIds: [$this->classA->id])));
        $this->assertSame([$student->id], $this->resolve($this->makeBatch(classIds: [$this->classB->id])));
    }

    public function test_resolution_uses_only_the_selected_academic_year_enrollment(): void
    {
        $otherYear = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => false]);

        $thisYear = $this->enrolledStudent($this->classA, suffix: 'Now');
        // The historical year is closed for edits; build its fixture through the
        // sanctioned lock escape hatch (see AcademicYearLock::withoutLock).
        $onlyOtherYear = AcademicYearLock::withoutLock(
            fn () => $this->enrolledStudent($this->classA, year: $otherYear, suffix: 'Old'),
        );

        $resolved = $this->resolve($this->makeBatch(classIds: [$this->classA->id]));

        $this->assertSame([$thisYear->id], $resolved);
        $this->assertNotContains($onlyOtherYear->id, $resolved);
    }

    public function test_all_mode_targets_every_enrolled_student_of_the_year(): void
    {
        $a1 = $this->enrolledStudent($this->classA, suffix: 'A1');
        $b1 = $this->enrolledStudent($this->classB, suffix: 'B1');

        $resolved = $this->resolve($this->makeBatch(mode: BillingBatch::TARGET_MODE_ALL));

        $this->assertEqualsCanonicalizing([$a1->id, $b1->id], $resolved);
    }
}
