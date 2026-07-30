<?php

namespace Tests\Feature;

use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Curricula\CurriculumResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Fees\FeeResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Permissions\PermissionResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\StudentResource;
use App\Filament\Resources\TeacherAssignments\TeacherAssignmentResource;
use App\Filament\Resources\TeacherResource;
use App\Filament\Resources\TeacherSalaries\TeacherSalaryResource;
use App\Filament\Resources\Users\UserResource;
use Tests\TestCase;

/**
 * Modern UI v3: the Filament Admin panel's navigationGroup values are made
 * to match the classic dashboard shell's group names exactly (previously a
 * mix of 'Академия', 'Зарплата', and even a stray Arabic 'المالية' on
 * StudentLedger), so a user sees the same information architecture whether
 * they're on /dashboard or /admin. This doesn't unify the two shells'
 * markup (Filament's own panel rendering is preserved intact per the batch
 * requirement), only the navigation grouping and accent color.
 */
class FilamentNavigationConsistencyTest extends TestCase
{
    public function test_academic_process_resources_share_the_dashboard_shells_group_name(): void
    {
        foreach ([AcademicYearResource::class, CurriculumResource::class, AttendanceResource::class] as $resource) {
            $this->assertSame('Учебный процесс', $resource::getNavigationGroup());
        }
    }

    public function test_student_resource_is_grouped_under_students_not_academic(): void
    {
        $this->assertSame('Ученики', StudentResource::getNavigationGroup());
    }

    public function test_teacher_related_resources_share_one_group(): void
    {
        foreach ([TeacherResource::class, TeacherAssignmentResource::class, TeacherSalaryResource::class] as $resource) {
            $this->assertSame('Учителя и сотрудники', $resource::getNavigationGroup());
        }
    }

    public function test_all_finance_resources_share_one_group(): void
    {
        foreach ([InvoiceResource::class, FeeResource::class, ExpenseResource::class] as $resource) {
            $this->assertSame('Финансы', $resource::getNavigationGroup());
        }
    }

    public function test_administration_resources_share_one_group(): void
    {
        foreach ([UserResource::class, RoleResource::class, PermissionResource::class] as $resource) {
            $this->assertSame('Администрирование', $resource::getNavigationGroup());
        }
    }

    public function test_no_navigation_group_is_a_stray_non_russian_string(): void
    {
        $groups = [
            AcademicYearResource::getNavigationGroup(),
            StudentResource::getNavigationGroup(),
            TeacherResource::getNavigationGroup(),
            InvoiceResource::getNavigationGroup(),
            UserResource::getNavigationGroup(),
        ];

        foreach ($groups as $group) {
            $this->assertMatchesRegularExpression('/^\p{Cyrillic}/u', (string) $group);
        }
    }
}
