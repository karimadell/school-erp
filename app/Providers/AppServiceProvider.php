<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// Contracts / Services
use App\Contracts\HolidayCalendar;
use App\Services\CurriculumAwareTimetableConflictChecker;
use App\Services\DatabaseHolidayCalendar;
use App\Services\TimetableConflictChecker;
use App\Support\ClassConflictRule;
use App\Support\CurriculumSubjectRule;
use App\Support\CurriculumWeeklyHoursRule;
use App\Support\DuplicateLessonConflictRule;
use App\Support\RoomConflictRule;
use App\Support\TeacherConflictRule;

// Models
use App\Models\User;
use App\Models\Student;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\StudentServiceSubscription;
use App\Models\TeacherAssignment;
use App\Models\Enrollment;
use App\Models\Attendance;
use App\Models\Exam;
use App\Models\StudentGrade;
use App\Models\LessonJournalEntry;
use App\Models\Quarter;
use App\Models\AcademicYearUnlock;
use App\Models\StudentSubjectEnrollment;

// Observer
use App\Observers\AuditObserver;
use App\Observers\AcademicYearLockObserver;
use App\Observers\ExamSnapshotObserver;
use App\Observers\CurriculumValidationObserver;
use App\Observers\TeacherAssignmentCurriculumObserver;
use App\Observers\StudentSubjectEnrollmentValidationObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // D1 Phase 2: holiday infrastructure only — no call site consults
        // this yet. See App\Contracts\HolidayCalendar's doc comment.
        $this->app->bind(HolidayCalendar::class, DatabaseHolidayCalendar::class);

        // The one conflict engine — every timetable UI resolves this
        // instead of running its own conflict SQL. Order matters:
        // DuplicateLessonConflictRule must run before ClassConflictRule/
        // TeacherConflictRule (see TimetableConflictChecker's doc comment).
        $this->app->bind(TimetableConflictChecker::class, function () {
            return new TimetableConflictChecker([
                new DuplicateLessonConflictRule(),
                new TeacherConflictRule(),
                new ClassConflictRule(),
                new RoomConflictRule(),
            ]);
        });

        // Batch 1 / Curriculum Enforcement: only the canonical UI
        // (TimetableGrid) resolves this — TimetableController (#1,
        // deprecated) keeps resolving the unmodified TimetableConflictChecker
        // binding above. Curriculum rules run first: a subject that isn't
        // even schedulable at all is a more fundamental problem than a
        // scheduling collision.
        $this->app->bind(CurriculumAwareTimetableConflictChecker::class, function () {
            return new CurriculumAwareTimetableConflictChecker([
                new CurriculumSubjectRule(),
                new CurriculumWeeklyHoursRule(),
                new DuplicateLessonConflictRule(),
                new TeacherConflictRule(),
                new ClassConflictRule(),
                new RoomConflictRule(),
            ]);
        });
    }

    public function boot(): void
    {
        User::observe(AuditObserver::class);
        Student::observe(AuditObserver::class);

        // لو الموديلات موجودة
        if (class_exists(CashAccount::class)) {
            CashAccount::observe(AuditObserver::class);
        }

        if (class_exists(CashTransaction::class)) {
            CashTransaction::observe(AuditObserver::class);
        }

        if (class_exists(StudentServiceSubscription::class)) {
            StudentServiceSubscription::observe(AuditObserver::class);
        }

        TeacherAssignment::observe(AuditObserver::class);
        AcademicYearUnlock::observe(AuditObserver::class);

        // Item 5 (Batch 10 / B8): Enrollment transfers/edits/deletions now
        // have an audit trail, matching the other sensitive access/
        // financial models already observed above.
        Enrollment::observe(AuditObserver::class);

        // Item 2: historical academic-year write locking. Excludes
        // Invoice (academic_year_id population/backfill handled
        // separately) and MealSubscription/Timetable/Fee (no academic-year
        // link, direct or transitive).
        Enrollment::observe(AcademicYearLockObserver::class);
        Attendance::observe(AcademicYearLockObserver::class);

        // Item 6 (B6): must be registered before AcademicYearLockObserver
        // for Exam — populates the academic_year_id/grade_id/stage_id
        // snapshot first, so the lock check below sees it already in
        // place and exercises the preferred snapshot path in
        // Exam::resolveAcademicYear(), not the legacy quarter fallback.
        Exam::observe(ExamSnapshotObserver::class);
        Exam::observe(AcademicYearLockObserver::class);
        StudentGrade::observe(AcademicYearLockObserver::class);
        TeacherAssignment::observe(AcademicYearLockObserver::class);
        LessonJournalEntry::observe(AcademicYearLockObserver::class);
        Quarter::observe(AcademicYearLockObserver::class);
        StudentServiceSubscription::observe(AcademicYearLockObserver::class);

        // Batch 11 / C2: registered before the validation observer below,
        // same ordering rationale as everywhere else — an unresolvable/
        // locked academic year must be reported before this record is
        // ever checked against Curriculum/Enrollment.
        StudentSubjectEnrollment::observe(AcademicYearLockObserver::class);

        // Batch 11 / C3: registered after AcademicYearLockObserver for
        // both models — if the academic year can't be resolved at all,
        // that rejection must surface first; this only ever runs once
        // the year is already successfully resolved (active or
        // explicitly unlocked), adding one further, independent check
        // ("is this subject actually in the curriculum").
        Exam::observe(CurriculumValidationObserver::class);
        StudentGrade::observe(CurriculumValidationObserver::class);

        // Batch 11 / C6: registered after AcademicYearLockObserver, same
        // ordering rationale as C3 above — an unresolvable/locked
        // academic year must be reported before this independent
        // "is this subject actually in the curriculum" check ever runs.
        // Kept as its own observer, separate from
        // CurriculumValidationObserver, since a TeacherAssignment is an
        // authorization grant, not a graded data row.
        TeacherAssignment::observe(TeacherAssignmentCurriculumObserver::class);

        // Batch 11 / C2: registered after AcademicYearLockObserver above
        // — validates the election itself (mandatory-type rejection,
        // student's enrolled grade must match the Curriculum's grade).
        StudentSubjectEnrollment::observe(StudentSubjectEnrollmentValidationObserver::class);
    }
}