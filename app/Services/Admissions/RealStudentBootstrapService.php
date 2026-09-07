<?php

namespace App\Services\Admissions;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Student;
use App\Models\StudentBootstrapImport;
use App\Services\AcademicStructureService;
use App\Services\Transport\TransportImportPreviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only by default; APPLY is an explicit, source-keyed transaction. */
class RealStudentBootstrapService
{
    public const TARGET_YEAR_ID = 1;

    public const TARGET_MODE_ID = 1;

    public const TARGET_DATE = '2026-09-01';

    private const CLASS_MAP = [
        '0' => [1, 3, 3], '1' => [2, 4, 4], '2' => [2, 5, 5], '3' => [2, 6, 6],
        '4' => [2, 7, 7], '5' => [3, 8, 9], '6' => [3, 9, 10], '7' => [3, 10, 11],
        '8' => [3, 11, 12], '9' => [3, 12, 13], '10' => [4, 13, 8], '11' => [4, 2, 2],
    ];

    public function __construct(
        private TransportImportPreviewService $workbooks,
        private AcademicStructureService $structure,
    ) {}

    public function preview(array $paths): array
    {
        $plan = $this->plan($paths);

        return ['mode' => 'PREVIEW', 'expected_students' => 64, 'expected_enrollments' => 64, 'new_students' => $plan->where('already_imported', false)->count(), 'new_enrollments' => $plan->where('already_imported', false)->count(), 'held' => 0, 'rows' => $plan->values()->all()];
    }

    public function apply(array $paths): array
    {
        $plan = $this->plan($paths);
        $this->assertGuard($plan);

        return DB::transaction(function () use ($plan) {
            $created = 0;
            foreach ($plan as $proposal) {
                $existing = StudentBootstrapImport::query()->where('source_key', $proposal['source_key'])->lockForUpdate()->first();
                if ($existing) {
                    continue;
                }
                $student = Student::create(['name' => $proposal['name'], 'status' => Student::STATUS_ACTIVE]);
                $enrollment = Enrollment::create([
                    'student_id' => $student->id, 'academic_year_id' => self::TARGET_YEAR_ID,
                    'enrollment_mode_id' => self::TARGET_MODE_ID, 'stage_id' => $proposal['stage_id'],
                    'grade_id' => $proposal['grade_id'], 'class_id' => $proposal['class_id'],
                    'academic_year' => AcademicYear::findOrFail(self::TARGET_YEAR_ID)->name, 'enrollment_date' => self::TARGET_DATE,
                    'enrolled_at' => self::TARGET_DATE, 'status' => 'active', 'is_active' => true,
                ]);
                StudentBootstrapImport::create(array_merge($proposal['source'], [
                    'source_key' => $proposal['source_key'], 'student_id' => $student->id, 'enrollment_id' => $enrollment->id,
                ]));
                $created++;
            }

            return ['mode' => 'APPLY', 'created_students' => $created, 'created_enrollments' => $created];
        });
    }

    private function plan(array $paths)
    {
        $allRows = collect($paths)->flatMap(fn ($path) => $this->workbooks->parseWorkbook($path));
        $types = $allRows->map(fn ($row) => $this->workbooks->classify($row));
        $counts = $types->countBy();
        if ($types->count() !== 75 || $counts->get('STUDENT', 0) !== 64
            || $counts->get('STAFF', 0) !== 11 || $counts->get('UNKNOWN', 0) !== 0) {
            throw ValidationException::withMessages(['bootstrap' => 'Expected exactly 64 student rows, 11 staff rows, and no unknown rows.']);
        }
        $rows = $allRows->filter(fn ($row) => $this->workbooks->classify($row) === 'STUDENT')->values();
        $year = AcademicYear::find(self::TARGET_YEAR_ID);
        $mode = EnrollmentMode::find(self::TARGET_MODE_ID);
        if (! $year || ! $mode || ! $year->is_active || ! $mode->is_active) {
            throw ValidationException::withMessages(['bootstrap' => 'Approved academic year or enrollment mode is unavailable.']);
        }

        return $rows->map(function (array $row) {
            $rawClass = trim((string) $row['raw_class']);
            if (! isset(self::CLASS_MAP[$rawClass])) {
                throw ValidationException::withMessages(['class' => "No approved mapping for source class {$rawClass}."]);
            }
            [$stage, $grade, $class] = self::CLASS_MAP[$rawClass];
            $this->structure->validatePlacement($stage, $grade, $class, requireActive: true);
            $source = ['source_file' => $row['source_file'], 'source_sheet' => $row['source_sheet'], 'source_row' => $row['source_row'], 'raw_full_name' => $row['raw_full_name'], 'raw_class' => $row['raw_class'], 'raw_pickup_point' => $row['raw_pickup_point'], 'raw_contact' => $row['raw_phone'], 'route' => $row['route']];

            return ['source' => $source, 'source_key' => hash('sha256', json_encode([$source['source_file'], $source['source_sheet'], $source['source_row'], $source['raw_full_name']], JSON_UNESCAPED_UNICODE)), 'name' => Student::normalizeRussianNamePart($row['raw_full_name']), 'stage_id' => $stage, 'grade_id' => $grade, 'class_id' => $class, 'already_imported' => StudentBootstrapImport::where('source_key', hash('sha256', json_encode([$source['source_file'], $source['source_sheet'], $source['source_row'], $source['raw_full_name']], JSON_UNESCAPED_UNICODE)))->exists()];
        });
    }

    private function assertGuard($plan): void
    {
        if ($plan->count() !== 64) {
            throw ValidationException::withMessages(['bootstrap' => 'Expected exactly 64 real student rows.']);
        }
        if ($plan->contains(fn ($row) => blank($row['name']))) {
            throw ValidationException::withMessages(['bootstrap' => 'Blank student identity found.']);
        }
    }
}
