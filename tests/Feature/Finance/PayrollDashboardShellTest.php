<?php

namespace Tests\Feature\Finance;

use App\Filament\Resources\TeacherSalaries\TeacherSalaryResource;
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

    public function test_no_legacy_salary_write_endpoint_is_re_enabled(): void
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

    public function test_approve_pay_and_edit_actions_still_point_to_the_canonical_filament_resource(): void
    {
        $employee = $this->employee('reception', 'Черновик Сотрудник');
        $draft = $this->service->create($employee, '2026-08-01', '10000', [], $this->admin);

        $html = $this->actingAs($this->admin)->get(route('dashboard.salaries.index'))->assertOk()->getContent();

        $this->assertStringContainsString(
            TeacherSalaryResource::getUrl('edit', ['record' => $draft]),
            $html,
        );
        $this->assertStringContainsString(TeacherSalaryResource::getUrl('index'), $html);
        $this->assertStringContainsString(route('dashboard.teacher-salaries.print', $draft), $html);
        $this->assertStringContainsString(route('dashboard.teacher-salaries.pdf', $draft), $html);
    }

    public function test_dashboard_salaries_index_page_posts_no_payroll_mutation_forms(): void
    {
        $employee = $this->employee('reception', 'Тест Сотрудник');
        $this->service->create($employee, '2026-08-01', '10000', [], $this->admin);

        $html = $this->actingAs($this->admin)->get(route('dashboard.salaries.index'))->assertOk()->getContent();

        // The only <form> on the page is the shared shell topbar's logout
        // form — the read-only mirror itself adds none, so every payroll
        // action is a plain GET link out to the canonical resource and
        // EmployeePayrollService/Filament remain the only place a payroll
        // record can actually be mutated or posted to cash.
        $this->assertSame(1, substr_count($html, '<form'));
        $this->assertStringNotContainsString(route('dashboard.salaries.store'), $html);
        $this->assertStringNotContainsString(route('dashboard.salaries.import'), $html);
    }

    private function employee(string $role, string $name): User
    {
        $employee = User::factory()->create(['name' => $name, 'is_active' => true]);
        $employee->assignRole($role);

        return $employee;
    }
}
