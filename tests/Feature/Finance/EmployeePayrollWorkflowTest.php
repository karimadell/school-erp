<?php

namespace Tests\Feature\Finance;

use App\Filament\Resources\TeacherSalaries\Pages\CreateTeacherSalary;
use App\Filament\Resources\TeacherSalaries\Pages\ListTeacherSalaries;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\EmployeeSalaryRate;
use App\Models\PayrollAdjustment;
use App\Models\Teacher;
use App\Models\TeacherSalary;
use App\Models\User;
use App\Services\Finance\EmployeePayrollService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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

    public function test_index_table_renders_for_teacher_and_non_teacher_payroll_records(): void
    {
        // Regression: TeacherSalary::$casts casts salary_month to a date, so
        // Eloquent's pluck() re-hydrates plucked values through the model's
        // cast, turning them into Carbon instances. The month filter used to
        // pluck through the Eloquent builder and then use those Carbon
        // instances as array keys in mapWithKeys(), which PHP rejects
        // (TypeError: "Cannot access offset of type Carbon on array"),
        // crashing the whole index page with a 500 as soon as any record
        // existed. The filter must pluck via the base query builder so the
        // raw date strings are used as keys instead.
        $teacherUser = $this->employee('teacher', 'Иван Учитель');
        Teacher::create(['user_id' => $teacherUser->id, 'first_name' => 'Иван', 'last_name' => 'Учитель', 'is_active' => true]);
        $this->service->create($teacherUser, '2026-09-15', '25000', [], $this->payrollUser, 'Учитель');

        $nonTeacher = $this->employee('reception', 'Анна Секретарь');
        $this->service->create($nonTeacher, '2026-08-01', '25000', [
            ['type' => 'bonus', 'amount' => '500', 'reason' => 'Премия'],
            ['type' => 'deduction', 'amount' => '300', 'reason' => 'Аванс'],
        ], $this->payrollUser, 'Секретарь');

        Livewire::actingAs($this->payrollUser)->test(ListTeacherSalaries::class)
            ->assertOk()
            ->assertSee('Иван Учитель')
            ->assertSee('Анна Секретарь')
            ->assertSee("25\u{A0}200,00");
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

    public function test_create_form_reactively_previews_net_salary_from_base_salary_and_adjustments(): void
    {
        $employee = $this->employee('reception', 'Секретарь');

        $form = Livewire::actingAs($this->payrollUser)->test(CreateTeacherSalary::class);

        $form->set('data.base_salary', '25000');
        $this->assertSame('25000.00', $form->get('data.net_salary'));

        $form->set('data.adjustments', [
            ['type' => PayrollAdjustment::TYPE_BONUS, 'amount' => '2000', 'reason' => 'Премия'],
        ]);
        $this->assertSame('27000.00', $form->get('data.net_salary'));

        $form->set('data.adjustments', [
            ['type' => PayrollAdjustment::TYPE_BONUS, 'amount' => '2000', 'reason' => 'Премия'],
            ['type' => PayrollAdjustment::TYPE_DEDUCTION, 'amount' => '500', 'reason' => 'Аванс'],
        ]);
        $this->assertSame('26500.00', $form->get('data.net_salary'));

        $form->set('data.adjustments', []);
        $this->assertSame('25000.00', $form->get('data.net_salary'));

        // The preview never reaches the persisted record: net_salary stays
        // server-computed by TeacherSalary::calculateNet() regardless of
        // what the client displayed.
        $form->set('data.employee_user_id', $employee->id)
            ->set('data.salary_month', '2026-09-01')
            ->set('data.adjustments', [
                ['type' => PayrollAdjustment::TYPE_BONUS, 'amount' => '2000', 'reason' => 'Премия'],
            ])
            ->set('data.net_salary', '999999.99')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('27000.00', TeacherSalary::sole()->net_salary);
    }

    public function test_net_salary_preview_reacts_to_editing_an_existing_adjustment_row_in_place(): void
    {
        // Mutates the exact nested state paths a real browser's
        // wire:model.live bindings send for repeater item fields
        // (data.adjustments.{index}.{field}), rather than replacing the
        // whole 'adjustments' array — this is what fillForm()/whole-array
        // set() calls do NOT exercise.
        $form = Livewire::actingAs($this->payrollUser)->test(CreateTeacherSalary::class);
        $form->set('data.base_salary', '25000');

        $form->set('data.adjustments', [
            ['type' => PayrollAdjustment::TYPE_BONUS, 'amount' => '500', 'reason' => 'test'],
        ]);
        $this->assertSame('25500.00', $form->get('data.net_salary'));

        // Bug scenario: change the SAME row's type in place, bonus -> deduction.
        $form->set('data.adjustments.0.type', PayrollAdjustment::TYPE_DEDUCTION);
        $this->assertSame('24500.00', $form->get('data.net_salary'));

        // deduction -> allowance
        $form->set('data.adjustments.0.type', PayrollAdjustment::TYPE_ALLOWANCE);
        $this->assertSame('25500.00', $form->get('data.net_salary'));

        // amount 500 -> 1000, still an allowance
        $form->set('data.adjustments.0.amount', '1000');
        $this->assertSame('26000.00', $form->get('data.net_salary'));

        // Add a second row (structural change, mirrors the Repeater's own
        // "add" action manipulating its array) then fill its nested fields
        // individually, as typing into a freshly added row would.
        $form->set('data.adjustments', [
            ['type' => PayrollAdjustment::TYPE_ALLOWANCE, 'amount' => '1000', 'reason' => 'test'],
            ['type' => null, 'amount' => null, 'reason' => null],
        ]);
        $form->set('data.adjustments.1.type', PayrollAdjustment::TYPE_DEDUCTION);
        $form->set('data.adjustments.1.amount', '300');
        $this->assertSame('25700.00', $form->get('data.net_salary'));

        // Remove the first row (structural change on the Repeater's own array).
        $form->set('data.adjustments', [
            ['type' => PayrollAdjustment::TYPE_DEDUCTION, 'amount' => '300', 'reason' => 'test'],
        ]);
        $this->assertSame('24700.00', $form->get('data.net_salary'));
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
