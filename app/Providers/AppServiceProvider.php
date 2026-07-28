<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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

// Observer
use App\Observers\AuditObserver;
use App\Observers\AcademicYearLockObserver;
use App\Observers\ExamSnapshotObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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
    }
}