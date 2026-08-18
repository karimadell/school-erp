<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use Illuminate\Validation\ValidationException;

class AcademicStructureService
{
    public function validatePlacement(
        int $stageId,
        int $gradeId,
        int $classId,
        bool $requireActive = false,
    ): SchoolClass {
        $stage = Stage::query()->find($stageId);
        $grade = Grade::query()->find($gradeId);
        $schoolClass = SchoolClass::query()->find($classId);

        $errors = [];

        if ($grade && $grade->stage_id !== $stageId) {
            $errors['grade_id'] = __('enrollments.validation.grade_stage_mismatch');
        }

        if ($schoolClass && $schoolClass->grade_id !== $gradeId) {
            $errors['class_id'] = __('enrollments.validation.class_grade_mismatch');
        }

        if ($requireActive && $stage && ! $stage->is_active) {
            $errors['stage_id'] = __('enrollments.validation.inactive_stage');
        }

        if ($requireActive && $schoolClass && ! $schoolClass->is_active) {
            $errors['class_id'] = __('enrollments.validation.inactive_class');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if (! $stage || ! $grade || ! $schoolClass) {
            throw ValidationException::withMessages([
                'class_id' => __('enrollments.validation.structure_changed'),
            ]);
        }

        return $schoolClass;
    }
}
