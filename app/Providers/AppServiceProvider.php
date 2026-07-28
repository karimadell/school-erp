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

        // Item 2: historical academic-year write locking. Excludes
        // Invoice (academic_year_id population/backfill handled
        // separately) and MealSubscription/Timetable/Fee (no academic-year
        // link, direct or transitive).
        Enrollment::observe(AcademicYearLockObserver::class);
        Attendance::observe(AcademicYearLockObserver::class);
        Exam::observe(AcademicYearLockObserver::class);
        StudentGrade::observe(AcademicYearLockObserver::class);
        TeacherAssignment::observe(AcademicYearLockObserver::class);
        LessonJournalEntry::observe(AcademicYearLockObserver::class);
        Quarter::observe(AcademicYearLockObserver::class);
        StudentServiceSubscription::observe(AcademicYearLockObserver::class);
    }
}