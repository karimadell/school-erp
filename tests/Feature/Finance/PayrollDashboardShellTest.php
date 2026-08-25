<?php

namespace Tests\Feature\Finance;

use App\Filament\Resources\TeacherSalaries\TeacherSalaryResource;
use App\Models\CashAccount;
use App\Models\TeacherSalary;
use App\Models\User;
use App\Services\Finance\EmployeePayrollService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the payroll dashboard-shell fix: the main
 * navigation used to send "Зарплаты сотрудников" straight into the
 * Filament /admin panel via a raw href, which swapped the whole
 * sidebar/layout out from under the user. SalaryController::index() now
 * renders a read-only dashboard-native mirror instead, so the nav item
 * can stay inside the unified dashboard shell like every other
 * dashboard.* entry.
 */
class PayrollDashboardShellTest extends TestCase
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

    public function test_payroll_navigation_stays_inside_the_unified_dashboard_shell(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('dashboard.salaries.index'), $html);
        $this->assertStringNotContainsString(route('filament.admin.resources.teacher-salaries.index'), $html);
    }

    public function test_dashboard_salaries_index_renders_with_an_existing_payroll_record(): void
    {
        $employee = $this->employee('reception', 'Анна Секретарь');
        $this->service->create($employee, '2026-08-01', '25000', [
            ['type' => 'bonus', 'amount' => '500', 'reason' => 'Премия'],
            ['type' => 'deduction', 'amount' => '300', 'reason' => 'Аванс'],
        ], $this->admin, 'Секретарь');

        $this->actingAs($this->admin)->get(route('dashboard.salaries.index'))
            ->assertOk()
            ->assertSee('Анна Секретарь')
            ->assertSee('Секретарь')
            ->assertSee('08.2026')
            ->assertSee('Черновик')
            ->assertSee('25,200.00');
    }

    public function test_edit_action_still_points_to_the_canonical_filament_resource(): void
    {
        // Edit stays Filament-backed: its reactive adjustments-repeater +
        // live net-salary preview would have to be duplicated in Blade to
        // bring it in-shell, which is out of proportion for a
        // low-frequency, drafts-only action.
        $employee = $this->employee('reception', 'Черновик Сотрудник');
        $draft = $this->service->create($employee, '2026-08-01', '10000', [], $this->admin);

        $html = $this->actingAs($this->admin)->get(route('dashboard.salaries.index'))->assertOk()->getContent();

        $this->assertStringContainsString(
            TeacherSalaryResource::getUrl('edit', ['record' => $draft]),
            $html,
        );
        $this->assertStringContainsString(route('dashboard.teacher-salaries.print', $draft), $html);
        $this->assertStringContainsString(route('dashboard.teacher-salaries.pdf', $draft), $html);
    }

    public function test_approve_and_pay_forms_target_only_the_canonical_bridge_routes(): void
    {
        $employee = $this->employee('reception', 'Тест Сотрудник');
        $draft = $this->service->create($employee, '2026-08-01', '10000', [], $this->admin);

        $html = $this->actingAs($this->admin)->get(route('dashboard.salaries.index'))->assertOk()->getContent();

        // The approve button posts straight to the dashboard bridge route,
        // never to the disabled legacy endpoints. (Edit legitimately still
        // links into Filament, so this doesn't assert the index URL is
        // absent — it would be a substring of the edit URL anyway.)
        $this->assertStringContainsString(route('dashboard.salaries.approve', $draft), $html);
        $this->assertStringNotContainsString(route('dashboard.salaries.store'), $html);
        $this->assertStringNotContainsString(route('dashboard.salaries.import'), $html);

        // Only the shared shell topbar's logout form plus the one approve
        // form belong to this page — no stray mutation forms.
        $this->assertSame(2, substr_count($html, '<form'));
    }

    public function test_approve_action_uses_the_canonical_service_and_returns_to_the_dashboard_shell(): void
    {
        $employee = $this->employee('reception', 'Одобряемый Сотрудник');
        $draft = $this->service->create($employee, '2026-08-01', '10000', [], $this->admin);

        $this->actingAs($this->admin)
            ->post(route('dashboard.salaries.approve', $draft))
            ->assertRedirect(route('dashboard.salaries.index'));

        $draft->refresh();
        $this->assertSame(TeacherSalary::STATUS_APPROVED, $draft->status);
        $this->assertSame($this->admin->id, $draft->approved_by);
        $this->assertNotNull($draft->approved_at);
    }

    public function test_approve_action_requires_the_approve_payroll_permission(): void
    {
        $employee = $this->employee('reception', 'Черновик Без Прав');
        $draft = $this->service->create($employee, '2026-08-01', '10000', [], $this->admin);

        // RolesAndPermissionsSeeder grants all four payroll permissions
        // directly to the 'admin' role, so this must use a different role
        // to actually exercise the permission gate.
        $noPermission = User::factory()->create(['is_active' => true]);
        $noPermission->assignRole('reception');
        $noPermission->givePermissionTo(['view payroll']);

        $this->actingAs($noPermission)
            ->post(route('dashboard.salaries.approve', $draft))
            ->assertForbidden();

        $this->assertSame(TeacherSalary::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_pay_form_page_stays_inside_the_dashboard_shell(): void
    {
        $employee = $this->employee('reception', 'Утверждённый Сотрудник');
        $draft = $this->service->create($employee, '2026-08-01', '10000', [], $this->admin);
        $approved = $this->service->approve($draft, $this->admin);
        CashAccount::create(['name' => 'Касса №1', 'type' => 'cash', 'balance' => '0.00', 'is_active' => true]);

        $html = $this->actingAs($this->admin)->get(route('dashboard.salaries.pay.create', $approved))
            ->assertOk()->getContent();

        $this->assertStringContainsString(route('dashboard.salaries.pay.store', $approved), $html);
        $this->assertStringContainsString('Касса №1', $html);
        $this->assertStringContainsString(route('dashboard.salaries.index'), $html);
        $this->assertStringNotContainsString(route('filament.admin.resources.teacher-salaries.index'), $html);
    }

    public function test_pay_action_uses_the_canonical_service_posts_cash_once_and_returns_to_the_dashboard_shell(): void
    {
        $employee = $this->employee('reception', 'Оплачиваемый Сотрудник');
        $draft = $this->service->create($employee, '2026-08-01', '10000', [], $this->admin);
        $approved = $this->service->approve($draft, $this->admin);
        $account = CashAccount::create(['name' => 'Банк', 'type' => 'bank', 'balance' => '0.00', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->post(route('dashboard.salaries.pay.store', $approved), [
                'cash_account_id' => $account->id,
                'payment_method' => 'bank',
            ])
            ->assertRedirect(route('dashboard.salaries.index'));

        $approved->refresh();
        $this->assertSame(TeacherSalary::STATUS_PAID, $approved->status);
        $this->assertDatabaseCount('cash_transactions', 1);
        $this->assertDatabaseHas('cash_transactions', [
            'teacher_salary_id' => $approved->id, 'type' => 'out', 'category' => 'expense', 'amount' => '10000.00',
        ]);

        // Replaying the bridge (e.g. a double click) must not post a
        // second cash transaction — same idempotency guarantee
        // EmployeePayrollService::pay() already gives Filament's action.
        $this->actingAs($this->admin)
            ->post(route('dashboard.salaries.pay.store', $approved), [
                'cash_account_id' => $account->id,
                'payment_method' => 'bank',
            ])
            ->assertRedirect(route('dashboard.salaries.index'));
        $this->assertDatabaseCount('cash_transactions', 1);
    }

    public function test_pay_routes_require_the_pay_payroll_permission(): void
    {
        $employee = $this->employee('reception', 'Без Прав На Оплату');
        $draft = $this->service->create($employee, '2026-08-01', '10000', [], $this->admin);
        $approved = $this->service->approve($draft, $this->admin);
        $account = CashAccount::create(['name' => 'Касса', 'type' => 'cash', 'balance' => '0.00', 'is_active' => true]);

        // RolesAndPermissionsSeeder grants all four payroll permissions
        // directly to the 'admin' role, so this must use a different role
        // to actually exercise the permission gate.
        $noPermission = User::factory()->create(['is_active' => true]);
        $noPermission->assignRole('reception');
        $noPermission->givePermissionTo(['view payroll']);

        $this->actingAs($noPermission)->get(route('dashboard.salaries.pay.create', $approved))->assertForbidden();
        $this->actingAs($noPermission)
            ->post(route('dashboard.salaries.pay.store', $approved), [
                'cash_account_id' => $account->id, 'payment_method' => 'cash',
            ])
            ->assertForbidden();

        $this->assertSame(TeacherSalary::STATUS_APPROVED, $approved->fresh()->status);
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_no_legacy_or_duplicate_write_endpoint_is_enabled(): void
    {
        $this->actingAs($this->admin)
            ->post(route('dashboard.salaries.store'), ['teacher_id' => 1, 'base_salary' => 1000])
            ->assertGone();

        $this->actingAs($this->admin)
            ->post(route('dashboard.salaries.import'))
            ->assertGone();

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
