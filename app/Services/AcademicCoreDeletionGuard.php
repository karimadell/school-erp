<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Quarter;
use App\Models\SchoolClass;
use App\Models\Stage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcademicCoreDeletionGuard
{
    public function ensureCanDelete(Model $record): void
    {
        $dependencies = match (true) {
            $record instanceof AcademicYear => $this->academicYearDependencies($record),
            $record instanceof Stage => $this->stageDependencies($record),
            $record instanceof Grade => $this->gradeDependencies($record),
            $record instanceof SchoolClass => $this->classDependencies($record),
            $record instanceof Quarter => $this->quarterDependencies($record),
            default => [],
        };

        if ($record instanceof AcademicYear && $record->is_active) {
            array_unshift($dependencies, __('academic_core.dependencies.active_year'));
        }

        $dependencies = array_values(array_unique($dependencies));

        if ($dependencies !== []) {
            throw ValidationException::withMessages([
                'delete' => __('academic_core.delete_blocked', [
                    'dependencies' => implode(', ', $dependencies),
                ]),
            ]);
        }
    }

    private function academicYearDependencies(AcademicYear $year): array
    {
        return $this->present([
            'enrollments' => ['enrollments', 'academic_year_id', $year->id],
            'quarters' => ['quarters', 'academic_year_id', $year->id],
            'curricula' => ['curricula', 'academic_year_id', $year->id],
            'teacher_assignments' => ['teacher_assignments', 'academic_year_id', $year->id],
            'class_teachers' => ['class_teachers', 'academic_year_id', $year->id],
            'journal' => ['lesson_journal_entries', 'academic_year_id', $year->id],
            'exams' => ['exams', 'academic_year_id', $year->id],
            'timetable' => ['timetable_versions', 'academic_year_id', $year->id],
            'calendar' => ['academic_calendars', 'academic_year_id', $year->id],
            'bell_schedules' => ['bell_schedules', 'academic_year_id', $year->id],
            'classrooms' => ['classrooms', 'academic_year_id', $year->id],
            'finance' => ['invoices', 'academic_year_id', $year->id],
            'finance_prices' => ['fee_prices', 'academic_year_id', $year->id],
            'billing' => ['billing_batches', 'academic_year_id', $year->id],
            'unlocks' => ['academic_year_unlocks', 'academic_year_id', $year->id],
            'holidays' => ['holidays', 'academic_year_id', $year->id],
        ]);
    }

    private function stageDependencies(Stage $stage): array
    {
        return $this->present([
            'grades' => ['grades', 'stage_id', $stage->id],
            'enrollments' => ['enrollments', 'stage_id', $stage->id],
            'students' => ['students', 'stage_id', $stage->id],
            'exams' => ['exams', 'stage_id', $stage->id],
        ]);
    }

    private function gradeDependencies(Grade $grade): array
    {
        return $this->present([
            'classes' => ['classes', 'grade_id', $grade->id],
            'enrollments' => ['enrollments', 'grade_id', $grade->id],
            'curricula' => ['curricula', 'grade_id', $grade->id],
            'exams' => ['exams', 'grade_id', $grade->id],
            'students' => ['students', 'grade_id', $grade->id],
            'legacy_classes' => ['class_rooms', 'grade_id', $grade->id],
            'finance' => ['fee_prices', 'grade_id', $grade->id],
            'finance_services' => ['fees', 'grade_id', $grade->id],
        ]);
    }

    private function classDependencies(SchoolClass $class): array
    {
        return $this->present([
            'students' => ['students', 'class_id', $class->id],
            'enrollments' => ['enrollments', 'class_id', $class->id],
            'teacher_assignments' => ['teacher_assignments', 'class_id', $class->id],
            'class_teachers' => ['class_teachers', 'school_class_id', $class->id],
            'journal' => ['lesson_journal_entries', 'class_id', $class->id],
            'exams' => ['exams', 'class_id', $class->id],
            'timetable' => ['timetable_entries', 'class_id', $class->id],
            'legacy_timetable' => ['timetables', 'class_id', $class->id],
            'subjects' => ['class_subject', 'class_id', $class->id],
            'billing' => ['billing_batch_classes', 'class_id', $class->id],
        ]);
    }

    private function quarterDependencies(Quarter $quarter): array
    {
        return $this->present([
            'exams' => ['exams', 'quarter_id', $quarter->id],
            'grades_history' => ['student_grades', 'quarter_id', $quarter->id],
        ]);
    }

    /** @param array<string, array{string, string, int}> $checks */
    private function present(array $checks): array
    {
        $dependencies = [];

        foreach ($checks as $label => [$table, $column, $id]) {
            if (DB::table($table)->where($column, $id)->exists()) {
                $dependencies[] = __('academic_core.dependencies.'.$label);
            }
        }

        return $dependencies;
    }
}
