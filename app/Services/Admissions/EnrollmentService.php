<?php

namespace App\Services\Admissions;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Student;
use App\Services\AcademicStructureService;
use Illuminate\Support\Facades\DB;

/**
 * Existing Student Enrollment Extraction (Phase 1).
 *
 * Owns exactly the domain/application behavior
 * EnrollmentController::store() already performed inline: resolving the
 * target AcademicYear, validating stage/grade/class placement, creating the
 * Enrollment row, and syncing Student.class_id when appropriate — behavior-
 * preserving, not a redesign. HTTP-layer concerns (request validation —
 * including the duplicate-year Rule::unique check — redirects, flash
 * messages, authorization/middleware) stay in the controller, unchanged.
 *
 * Deliberately a single create() method. No findOrCreateEnrollment(),
 * ensureEnrollment(), annualRenew(), promoteStudent(), or renewServices() —
 * those belong to a future Existing Student → New Academic Year workflow,
 * not this extraction. This service creates zero Invoice, Fee charge,
 * ServiceCoverage, or StudentServiceSubscription records — Enrollment
 * creation has no Finance side effects, exactly as before extraction.
 */
class EnrollmentService
{
    public function __construct(private AcademicStructureService $structure)
    {
    }

    /**
     * @param  array{academic_year_id: int, enrollment_mode_id?: ?int, stage_id: int, grade_id: int, class_id: int, enrollment_date?: ?string, status: string, notes?: ?string}  $data
     *         Already validated by the caller (see
     *         EnrollmentController::store()'s $request->validate() call,
     *         including its duplicate-year Rule::unique check) — this
     *         method trusts its shape exactly as the inline controller code
     *         it replaces did.
     */
    public function create(Student $student, array $data): Enrollment
    {
        // The human-readable academic_year label is derived server-side from
        // the selected year — never a hand-typed value — so academic_year_id
        // and its label can never disagree.
        $year = AcademicYear::findOrFail($data['academic_year_id']);
        $this->structure->validatePlacement(
            (int) $data['stage_id'],
            (int) $data['grade_id'],
            (int) $data['class_id'],
            requireActive: true,
        );

        return DB::transaction(function () use ($data, $student, $year) {
            // A new enrollment never deactivates another academic year's
            // enrollment. is_active describes only this record's own year —
            // it is not a global "current enrollment" flag across years.
            // The student's current placement is derived separately, from
            // whichever enrollment is linked to the currently active
            // AcademicYear (see Student::currentEnrollment()).
            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $data['academic_year_id'],
                'academic_year' => $year->name,
                'enrollment_mode_id' => $data['enrollment_mode_id'] ?? null,

                'stage_id' => $data['stage_id'],
                'grade_id' => $data['grade_id'],
                'class_id' => $data['class_id'],

                'enrollment_date' => $data['enrollment_date'] ?? now()->toDateString(),
                'enrolled_at' => $data['enrollment_date'] ?? now()->toDateString(),

                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['status'] === 'active',
            ]);

            if ($data['status'] === 'active' && $year->is_active) {
                $student->update([
                    'class_id' => $data['class_id'],
                ]);
            }

            return $enrollment;
        });
    }
}
