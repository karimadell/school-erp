<?php

namespace Tests\Feature;

use App\Filament\Pages\FinanceMonthlyReport;
use App\Filament\Pages\FinancialReport;
use App\Filament\Pages\ReportCard;
use App\Filament\Pages\StudentFinance;
use App\Filament\Pages\StudentFinanceReport;
use App\Filament\Pages\StudentLedger;
use App\Filament\Pages\StudentProfile;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Buses\BusResource;
use App\Filament\Resources\ClassResource;
use App\Filament\Resources\Curricula\CurriculumResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FeePrices\FeePriceResource;
use App\Filament\Resources\Fees\FeeResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\LessonJournalEntries\LessonJournalEntryResource;
use App\Filament\Resources\Permissions\PermissionResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\StudentGrades\StudentGradeResource;
use App\Filament\Resources\StudentResource;
use App\Filament\Resources\TeacherAssignments\TeacherAssignmentResource;
use App\Filament\Resources\TeacherResource;
use App\Filament\Resources\TeacherSalaries\TeacherSalaryResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Facades\Filament;
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

    public function test_admin_navigation_groups_have_an_explicit_product_order(): void
    {
        $this->assertSame([
            'Учебный процесс',
            'Ученики',
            'Учителя и сотрудники',
            'Финансы',
            'Транспорт',
            'Администрирование',
        ], Filament::getPanel('admin')->getNavigationGroups());
    }

    public function test_navigation_items_have_the_expected_order_within_each_group(): void
    {
        $this->assertSame([10, 20, 30, 40, 50, 60, 70], [
            AcademicYearResource::getNavigationSort(),
            ClassResource::getNavigationSort(),
            CurriculumResource::getNavigationSort(),
            LessonJournalEntryResource::getNavigationSort(),
            AttendanceResource::getNavigationSort(),
            StudentGradeResource::getNavigationSort(),
            ReportCard::getNavigationSort(),
        ]);

        $this->assertSame([10, 20], [
            StudentResource::getNavigationSort(),
            StudentProfile::getNavigationSort(),
        ]);

        $this->assertSame([10, 20, 30], [
            TeacherResource::getNavigationSort(),
            TeacherAssignmentResource::getNavigationSort(),
            TeacherSalaryResource::getNavigationSort(),
        ]);

        $this->assertSame([10, 20, 30, 40, 50, 60, 70, 80, 90], [
            FeeResource::getNavigationSort(),
            FeePriceResource::getNavigationSort(),
            InvoiceResource::getNavigationSort(),
            ExpenseResource::getNavigationSort(),
            FinancialReport::getNavigationSort(),
            FinanceMonthlyReport::getNavigationSort(),
            StudentLedger::getNavigationSort(),
            StudentFinance::getNavigationSort(),
            StudentFinanceReport::getNavigationSort(),
        ]);

        $this->assertSame(10, BusResource::getNavigationSort());

        $this->assertSame([10, 20, 30], [
            UserResource::getNavigationSort(),
            RoleResource::getNavigationSort(),
            PermissionResource::getNavigationSort(),
        ]);
    }

    public function test_primary_navigation_labels_are_russian_and_consistent(): void
    {
        $this->assertSame('Учебные годы', AcademicYearResource::getNavigationLabel());
        $this->assertSame('Учебные планы', CurriculumResource::getNavigationLabel());
        $this->assertSame('Журнал уроков', LessonJournalEntryResource::getNavigationLabel());
        $this->assertSame('Услуги и сборы', FeeResource::getNavigationLabel());
        $this->assertSame('Расходы', ExpenseResource::getNavigationLabel());
        $this->assertSame('Финансы ученика', StudentLedger::getNavigationLabel());
        $this->assertSame('Профиль ученика', StudentProfile::getNavigationLabel());
    }

    public function test_legacy_bus_resource_is_not_discovered(): void
    {
        $this->assertFalse(\App\Filament\Resources\BusResource::isDiscovered());
        $this->assertNotContains(
            \App\Filament\Resources\BusResource::class,
            Filament::getPanel('admin')->getResources(),
        );
        $this->assertContains(BusResource::class, Filament::getPanel('admin')->getResources());
    }

    public function test_report_card_does_not_advertise_an_active_pdf_download(): void
    {
        $view = file_get_contents(resource_path('views/filament/pages/report-card.blade.php'));

        $this->assertStringNotContainsString('wire:click="downloadPdf"', $view);
        $this->assertStringContainsString('PDF пока недоступен', $view);
        $this->assertStringContainsString('disabled', $view);
    }
}
