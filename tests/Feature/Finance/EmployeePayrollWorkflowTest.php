<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\EmployeeSalaryRate;
use App\Models\Teacher;
use App\Models\TeacherSalary;
use App\Models\User;
use App\Services\Finance\EmployeePayrollService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeePayrollWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $payrollUser;

    private EmployeePayrollService $service;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->payrollUser = User::factory()->create(['is_active' => true]);
        $this->payrollUser->assignRole('admin');
        $this->payrollUser->givePermissionTo(['view payroll', 'manage payroll', 'approve payroll', 'pay payroll']);
        $this->service = app(EmployeePayrollService::class);
    }

    public function test_teacher_and_non_teacher_share_canonical_employee_payroll(): void
    {
        $teacherUser = $this->employee('teacher', 'Иван Учитель');
        Teacher::create(['user_id' => $teacherUser->id, 'first_name' => 'Иван', 'last_name' => 'Учитель', 'is_active' => true]);
        $secretary = $this->employee('reception', 'Анна Секретарь');

        $teacherPayroll = $this->service->create($teacherUser, '2026-09-15', '25000', [], $this->payrollUser, 'Учитель');
        $staffPayroll = $this->service->create($secretary, '2026-09-01', '18000', [], $this->payrollUser, 'Секретарь');

        $this->assertSame($teacherUser->id, $teacherPayroll->employee_user_id);
        $this->assertNotNull($teacherPayroll->teacher_id);
        $this->assertNull($staffPayroll->teacher_id);
        $this->assertSame('Анна Секретарь', $staffPayroll->employee_display_name);
    }

    public function test_duplicate_employee_month_is_rejected(): void
    {
        $employee = $this->employee('accountant', 'Бухгалтер');
        $this->service->create($employee, '2026-09-01', '20000', [], $this->payrollUser);

        $this->expectException(ValidationException::class);
        $this->service->create($employee, '2026-09-20', '20000', [], $this->payrollUser);
    }

    public function test_effective_salary_rates_preserve_historical_payroll_snapshots(): void
    {
        $employee = $this->employee('accountant', 'Бухгалтер');
        $september = $this->service->create($employee, '2026-09-01', '25000', [], $this->payrollUser);
        $january = $this->service->create($employee, '2027-01-01', '28000', [], $this->payrollUser);

        $this->assertSame('25000.00', $september->fresh()->base_salary);
        $this->assertSame('28000.00', $january->base_salary);
        $this->assertSame(['25000.00', '28000.00'], EmployeeSalaryRate::where('employee_user_id', $employee->id)->orderBy('effective_from')->pluck('amount')->all());
        $this->assertSame('2026-12-31', EmployeeSalaryRate::where('employee_user_id', $employee->id)->oldest('effective_from')->first()->effective_to->toDateString());
    }

    public function test_multiple_auditable_adjustments_calculate_net_with_decimal_math(): void
    {
        $employee = $this->employee('reception', 'Секретарь');
        $payroll = $this->service->create($employee, '2026-09-01', '25000.00', [
            ['type' => 'bonus', 'amount' => '2000.00', 'reason' => 'Отличная работа'],
            ['type' => 'bonus', 'amount' => '250.25', 'reason' => 'Проект'],
            ['type' => 'allowance', 'amount' => '1000.00', 'reason' => 'Дополнительные обязанности'],
            ['type' => 'deduction', 'amount' => '500.10', 'reason' => 'Аванс'],
            ['type' => 'deduction', 'amount' => '50.05', 'reason' => 'Другое основание'],
        ], $this->payrollUser);

        $this->assertSame('2250.25', $payroll->bonus);
        $this->assertSame('1000.00', $payroll->allowances);
        $this->assertSame('550.15', $payroll->deductions);
        $this->assertSame('27700.10', $payroll->net_salary);
        $this->assertSame(5, $payroll->adjustments()->count());
        $this->assertSame($this->payrollUser->id, $payroll->adjustments()->first()->created_by);
    }

    public function test_invalid_adjustment_and_negative_net_are_rejected(): void
    {
        $employee = $this->employee('reception', 'Работник');
        try {
            $this->service->create($employee, '2026-09-01', '100', [['type' => 'bonus', 'amount' => '-1', 'reason' => 'bad']], $this->payrollUser);
            $this->fail('Negative adjustment accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('teacher_salaries', 0);
        }

        $this->expectException(ValidationException::class);
        $this->service->create($employee, '2026-10-01', '100', [['type' => 'deduction', 'amount' => '101', 'reason' => 'too much']], $this->payrollUser);
    }

    public function test_accrual_is_non_cash_and_payment_posts_once_to_cash_expense_reporting(): void
    {
        $employee = $this->employee('accountant', 'Бухгалтер');
        $account = CashAccount::create(['name' => 'Банк', 'type' => 'bank', 'balance' => '100000.00', 'is_active' => true]);
        $payroll = $this->service->create($employee, '2026-09-01', '25000', [['type' => 'bonus', 'amount' => '1000', 'reason' => 'Премия']], $this->payrollUser);
        $this->assertDatabaseCount('cash_transactions', 0);

        $approved = $this->service->approve($payroll, $this->payrollUser);
        $paid = $this->service->pay($approved, $account, 'bank', $this->payrollUser);
        $replayed = $this->service->pay($paid, $account, 'bank', $this->payrollUser);

        $this->assertSame(TeacherSalary::STATUS_PAID, $paid->status);
        $this->assertSame($paid->cash_transaction_id, $replayed->cash_transaction_id);
        $this->assertDatabaseCount('cash_transactions', 1);
        $this->assertDatabaseHas('cash_transactions', ['teacher_salary_id' => $payroll->id, 'type' => 'out', 'category' => 'expense', 'amount' => '26000.00']);
        $this->assertSame('74000.00', $account->fresh()->balance);
        $this->assertSame('26000.00', bcadd((string) CashTransaction::out()->category(CashTransaction::CATEGORY_EXPENSE)->sum('amount'), '0', 2));
        $this->payrollUser->givePermissionTo('view cash reports');
        $this->actingAs($this->payrollUser)->get(route('dashboard.cash.reports'))
            ->assertOk()->assertSee('26,000.00');
    }

    public function test_approved_and_paid_history_cannot_be_silently_edited(): void
    {
        $employee = $this->employee('reception', 'Сотрудник');
        $payroll = $this->service->create($employee, '2026-09-01', '10000', [], $this->payrollUser);
        $payroll = $this->service->approve($payroll, $this->payrollUser);

        $this->expectException(ValidationException::class);
        $payroll->update(['base_salary' => '1.00']);
    }

    public function test_print_pdf_russian_labels_and_permissions(): void
    {
        $employee = $this->employee('reception', 'Анна Секретарь');
        $payroll = $this->service->create($employee, '2026-09-01', '18000', [['type' => 'allowance', 'amount' => '1000', 'reason' => 'Обязанности']], $this->payrollUser, 'Секретарь');

        $this->actingAs($this->payrollUser)->get(route('dashboard.teacher-salaries.print', $payroll))
            ->assertOk()->assertSee('Расчётный лист сотрудника')->assertSee('Анна Секретарь')->assertSee('Надбавка')->assertSee('19,000.00');
        $this->actingAs($this->payrollUser)->get(route('dashboard.teacher-salaries.pdf', $payroll))
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $unauthorized = User::factory()->create(['is_active' => true]);
        $unauthorized->assignRole('reception');
        $this->actingAs($unauthorized)->get(route('dashboard.teacher-salaries.print', $payroll))->assertForbidden();
    }

    public function test_payroll_permissions_are_granted_to_admin_only(): void
    {
        foreach (['view payroll', 'manage payroll', 'approve payroll', 'pay payroll'] as $permission) {
            $permissionModel = Permission::where('name', $permission)->firstOrFail();
            $this->assertSame(['admin'], $permissionModel->roles()->pluck('name')->all());
        }
    }

    public function test_legacy_salary_routes_cannot_create_a_second_cash_posting_path(): void
    {
        $this->actingAs($this->payrollUser)->get(route('dashboard.salaries.index'))
            ->assertRedirect(route('filament.admin.resources.teacher-salaries.index'));
        $this->actingAs($this->payrollUser)->post(route('dashboard.salaries.store'), [
            'teacher_id' => 1, 'base_salary' => 1000,
        ])->assertGone();
        $this->assertDatabaseCount('teacher_salaries', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    private function employee(string $role, string $name): User
    {
        $employee = User::factory()->create(['name' => $name, 'is_active' => true]);
        $employee->assignRole($role);

        return $employee;
    }
}
