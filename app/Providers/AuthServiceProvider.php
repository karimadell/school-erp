<?php

namespace App\Providers;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Expense;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\LessonJournalEntry;
use App\Models\StudentFile;
use App\Models\StudentServiceSubscription;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Policies\AcademicYearPolicy;
use App\Policies\CurriculumPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\FeePolicy;
use App\Policies\FeePricePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\LessonJournalEntryPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\StudentFilePolicy;
use App\Policies\StudentServiceSubscriptionPolicy;
use App\Policies\TeacherAssignmentPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        StudentFile::class => StudentFilePolicy::class,

        // Item 2 (Batch 10)
        AcademicYear::class => AcademicYearPolicy::class,

        // Item 8 (Batch 10 / C1)
        Curriculum::class => CurriculumPolicy::class,

        // Batch 6
        Fee::class => FeePolicy::class,
        FeePrice::class => FeePricePolicy::class,
        Invoice::class => InvoicePolicy::class,
        Expense::class => ExpensePolicy::class,
        LessonJournalEntry::class => LessonJournalEntryPolicy::class,
        StudentServiceSubscription::class => StudentServiceSubscriptionPolicy::class,
        TeacherAssignment::class => TeacherAssignmentPolicy::class,
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        Permission::class => PermissionPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
