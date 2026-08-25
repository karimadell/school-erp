<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\SchoolSetting;
use App\Models\Teacher;
use App\Models\TeacherSalary;
use App\Models\User;
use App\Services\Finance\EmployeePayrollService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for the compact A5 payslip redesign. Print/PDF are
 * read-only views over an existing TeacherSalary — these tests assert
 * layout/content, and separately assert the record and its cash
 * transaction are byte-for-byte unchanged by rendering either view.
 */
class PayslipPrintLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private EmployeePayrollService $service;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->admin->givePermissionTo(['view payroll', 'manage payroll', 'approve payroll', 'pay payroll']);
        $this->service = app(EmployeePayrollService::class);
    }

    private function paidPayroll(): TeacherSalary
    {
        $disk = config('filesystems.uploads.public');
        Storage::fake($disk);
        $jpg = file_get_contents(storage_path('app/public/branding/ka8lgzQDsFdfIVSWqqZ4q4pgkziGLmwHvjBsYhj9.jpg'));
        Storage::disk($disk)->put('branding/logo.jpg', $jpg);
        SchoolSetting::current()->update(['printing_logo_path' => 'branding/logo.jpg']);

        $employee = User::factory()->create(['name' => 'Администратор UAT', 'is_active' => true]);
        $employee->assignRole('reception');
        $salary = $this->service->create($employee, '2026-08-01', '25000', [
            ['type' => 'bonus', 'amount' => '500', 'reason' => 'Премия'],
            ['type' => 'deduction', 'amount' => '300', 'reason' => 'Аванс'],
        ], $this->admin, 'admin');
        $salary = $this->service->approve($salary, $this->admin);
        $account = CashAccount::create(['name' => 'Банк', 'type' => 'bank', 'balance' => '0.00', 'is_active' => true]);

        return $this->service->pay($salary, $account, 'bank', $this->admin);
    }

    public function test_print_route_renders_a_compact_a5_receipt_with_logo_and_signature_fields(): void
    {
        $salary = $this->paidPayroll();

        $html = $this->actingAs($this->admin)->get(route('dashboard.teacher-salaries.print', $salary))
            ->assertOk()->getContent();

        // Content required by the redesign.
        $this->assertStringContainsString('Администратор UAT', $html);
        $this->assertStringContainsString('admin', $html); // position
        $this->assertStringContainsString('25,000.00', $html); // base salary
        $this->assertStringContainsString('500.00', $html); // bonus
        $this->assertStringContainsString('300.00', $html); // deduction
        $this->assertStringContainsString('25,200.00', $html); // net salary
        $this->assertStringContainsString(__('teacher_salary.statuses.paid'), $html);

        // Signature section.
        $this->assertStringContainsString(__('teacher_salary.received_by').': ______________________________', $html);
        $this->assertStringContainsString(__('teacher_salary.employee_signature').': ______________________', $html);
        $this->assertStringContainsString(__('teacher_salary.received_date').': ____ / ____ / ______', $html);
        $this->assertStringContainsString(__('teacher_salary.cashier_signature').': ______________________', $html);

        // Logo/branding via the shared document-header component.
        $this->assertStringContainsString('data:image/jpeg;base64,', $html);

        // A5 portrait @page rule for the browser print path.
        $this->assertStringContainsString('@page{size:A5 portrait', $html);
    }

    public function test_pdf_route_returns_a_single_page_a5_pdf(): void
    {
        $salary = $this->paidPayroll();

        $response = $this->actingAs($this->admin)->get(route('dashboard.teacher-salaries.pdf', $salary))
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $content = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $content);
        // dompdf's built-in "a5" paper size in points.
        $this->assertStringContainsString('419.530 595.280', $content);
        // Exactly one page.
        $this->assertStringContainsString('/Count 1', $content);
        $this->assertStringNotContainsString('/Count 2', $content);
    }

    public function test_print_and_pdf_do_not_mutate_the_payroll_or_cash_transaction(): void
    {
        $salary = $this->paidPayroll();
        $before = $salary->fresh()->getAttributes();
        $cashBefore = $salary->cashTransaction->getAttributes();

        $this->actingAs($this->admin)->get(route('dashboard.teacher-salaries.print', $salary))->assertOk();
        $this->actingAs($this->admin)->get(route('dashboard.teacher-salaries.pdf', $salary))->assertOk();

        $this->assertDatabaseCount('teacher_salaries', 1);
        $this->assertDatabaseCount('cash_transactions', 1);
        $this->assertSame($before, $salary->fresh()->getAttributes());
        $this->assertSame($cashBefore, $salary->fresh()->cashTransaction->getAttributes());
    }

    public function test_print_shows_legacy_allowance_fallback_when_there_are_no_adjustment_rows(): void
    {
        // Mirrors the pre-existing legacy bonus/deduction fallback test:
        // a record created before adjustments existed, with only the
        // aggregate columns set and no adjustment rows.
        $teacher = Teacher::create([
            'first_name' => 'Легаси', 'last_name' => 'Запись',
            'email' => 'legacy.allowance@example.invalid', 'is_active' => true,
        ]);
        $salary = TeacherSalary::create([
            'teacher_id' => $teacher->id, 'base_salary' => 15000, 'bonus' => 0, 'allowances' => 750, 'deductions' => 0,
            'salary_month' => '2026-07-01',
        ]);

        $html = $this->actingAs($this->admin)->get(route('dashboard.teacher-salaries.print', $salary))
            ->assertOk()->getContent();

        $this->assertStringContainsString(__('teacher_salary.allowance'), $html);
        $this->assertStringContainsString('750.00', $html);
    }
}
