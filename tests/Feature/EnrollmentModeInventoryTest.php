<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves enrollment-modes:inventory is a pure read: every diagnostic it is
 * meant to surface actually fires against a deliberately messy fixture set
 * (a canonical code with the wrong name, an unknown/test code sharing a
 * canonical name, a duplicate name across two unknown codes, a historically
 * referenced unknown code, and a NULL enrollment_mode_id), and running it
 * changes nothing in any of the tables it reads from.
 */
class EnrollmentModeInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Elementary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);

        return SchoolClass::forceCreate(['code' => 'A', 'name_ar' => 'A', 'grade_id' => $grade->id]);
    }

    private function enrollment(Student $student, AcademicYear $year, SchoolClass $class, ?int $modeId): Enrollment
    {
        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'enrollment_mode_id' => $modeId,
            'stage_id' => $class->grade->stage_id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'academic_year' => $year->name,
            'enrollment_date' => '2026-09-01',
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    public function test_command_surfaces_every_diagnostic_and_performs_zero_writes(): void
    {
        $class = $this->makeClass();
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $fullTime = EnrollmentMode::create([
            'code' => 'full_time', 'name_ru' => 'Очная форма обучения', 'is_active' => true, 'display_order' => 0,
        ]);
        // Canonical code exists, but its name was hand-edited away from the
        // expected value — must be flagged as a mismatch, not silently OK.
        $family = EnrollmentMode::create([
            'code' => 'family', 'name_ru' => 'Семейная (черновик)', 'is_active' => false, 'display_order' => 1,
        ]);
        // Unknown/non-canonical code that happens to hold the name expected
        // for the still-missing "external" canonical code, and is
        // historically referenced by an Enrollment — must never be treated
        // as safe to delete or silently repurposed.
        $legacyA = EnrollmentMode::create([
            'code' => 'legacy_test_mode_a', 'name_ru' => 'Экстернат', 'is_active' => true, 'display_order' => 99,
        ]);
        // A second unknown code sharing the same name as $legacyA, with no
        // Enrollment references at all — proves duplicate-name detection
        // and the "not referenced" branch independently of $legacyA.
        $legacyB = EnrollmentMode::create([
            'code' => 'legacy_test_mode_b', 'name_ru' => 'Экстернат', 'is_active' => false, 'display_order' => 100,
        ]);

        $studentA = Student::forceCreate(['name' => 'Student A']);
        $studentB = Student::forceCreate(['name' => 'Student B']);
        $studentC = Student::forceCreate(['name' => 'Student C']);

        $this->enrollment($studentA, $year, $class, $fullTime->id);
        $this->enrollment($studentB, $year, $class, $legacyA->id);
        $this->enrollment($studentC, $year, $class, null);

        $before = $this->snapshotCounts();
        $beforeValues = $this->snapshotRowValues();

        $exitCode = Artisan::call('enrollment-modes:inventory');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        // Canonical existence / absence.
        $this->assertStringContainsString('OK — canonical code "full_time"', $output);
        $this->assertStringContainsString('OK — canonical code "family"', $output);
        $this->assertStringContainsString('MISSING — canonical code "external"', $output);
        $this->assertStringContainsString('MISSING — canonical code "no_enrollment"', $output);

        // Name mismatch on an existing canonical code.
        $this->assertStringContainsString(
            'NAME MISMATCH — code "family" (id='.$family->id.') has name_ru "Семейная (черновик)", expected "Семейная форма обучения".',
            $output
        );

        // Expected name found parked under a non-canonical code.
        $this->assertStringContainsString(
            'NAME UNDER ANOTHER CODE — expected name "Экстернат" (for "external") is currently on code "legacy_test_mode_a"',
            $output
        );

        // Unknown/non-canonical codes, correctly classified as referenced vs. not.
        $this->assertStringContainsString(
            'UNKNOWN/NON-CANONICAL — code "legacy_test_mode_a" (id='.$legacyA->id.', name_ru="Экстернат") is not one of the four canonical codes.'
                .' HISTORICALLY REFERENCED by 1 Enrollment row(s) — must not be deleted or repurposed without review.',
            $output
        );
        $this->assertStringContainsString(
            'UNKNOWN/NON-CANONICAL — code "legacy_test_mode_b" (id='.$legacyB->id.', name_ru="Экстернат") is not one of the four canonical codes.'
                .' Not currently referenced by any Enrollment.',
            $output
        );

        // Duplicate name across two unknown codes.
        $this->assertStringContainsString('DUPLICATE NAME — name_ru "Экстернат" is shared by codes', $output);
        $this->assertStringContainsString('legacy_test_mode_a', $output);
        $this->assertStringContainsString('legacy_test_mode_b', $output);

        // NULL enrollment_mode_id reference.
        $this->assertStringContainsString('NULL REFERENCES — 1 Enrollment row(s) have enrollment_mode_id IS NULL.', $output);

        // Row-level output for every mode and the totals section.
        $this->assertStringContainsString('Total Enrollment rows', $output);
        $this->assertStringContainsString('No data was created, updated, or deleted.', $output);

        // Row counts alone would miss an in-place UPDATE (same row count,
        // mutated content) — the value-level snapshot below is what
        // actually proves no existing row's attributes changed.
        $this->assertSame($before, $this->snapshotCounts());
        $this->assertSame($beforeValues, $this->snapshotRowValues());
    }

    /**
     * @return array<string, int>
     */
    private function snapshotCounts(): array
    {
        return [
            'enrollment_modes' => DB::table('enrollment_modes')->count(),
            'enrollments' => DB::table('enrollments')->count(),
            'fees' => DB::table('fees')->count(),
            'fee_prices' => DB::table('fee_prices')->count(),
            'invoices' => DB::table('invoices')->count(),
        ];
    }

    /**
     * Deterministically ordered, full-attribute (including timestamps)
     * snapshot of every enrollment_modes and enrollments row — strict
     * equality against this after running the command catches an in-place
     * UPDATE that a mere row-count comparison would silently miss.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function snapshotRowValues(): array
    {
        return [
            'enrollment_modes' => EnrollmentMode::query()->orderBy('id')->get()->toArray(),
            'enrollments' => Enrollment::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
