<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardShellTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_dashboard_renders_the_original_bootstrap_erp_shell(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee('bootstrap@5.3.3', false);
        $response->assertSee('width:260px', false);
        $response->assertSee('navbar navbar-light bg-light', false);
        $response->assertSee('container-fluid p-4', false);
        $response->assertSee('bg-success shadow-sm', false);
        $response->assertDontSee('dashboard-v2', false);
        $response->assertDontSee('ui2-', false);
        $response->assertDontSee('filament v', false);
        $response->assertDontSee('filamentphp.com', false);

        foreach ([
            'Панель управления', 'Структура школы', 'Предметы', 'Посещаемость',
            'Табели успеваемости', 'Все ученики', 'Зачисление', 'Учителя',
            'Счета', 'Услуги', 'Касса', 'Кассовые счета', 'Расходы',
            'Финансовые отчёты', 'Пользователи', 'Роли и разрешения', 'Журнал действий',
        ] as $expected) {
            $response->assertSee($expected);
        }
    }

    public function test_incomplete_modules_stay_hidden_from_the_sidebar(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard.index'));

        $response->assertOk();

        // 'Настройки школы' (School Settings) has since shipped and is a live,
        // admin-gated sidebar entry (SchoolSettingController), so it is no
        // longer among the hidden, unimplemented placeholders below.
        foreach (['Экзамены', 'Родители', 'Документы', 'Сотрудники', 'Резервные копии'] as $hidden) {
            $response->assertDontSee($hidden);
        }
    }

    public function test_sidebar_links_to_the_safe_buses_module_without_exposing_classic_transport(): void
    {
        $this->assertTrue(\Route::has('filament.admin.resources.buses.index'));

        $response = $this->actingAs($this->admin())->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee('Автобусы');
        $response->assertSee(route('filament.admin.resources.buses.index'), false);
        $response->assertDontSee(route('dashboard.transport.index'), false);
    }

    public function test_shell_is_right_to_left_under_arabic_locale(): void
    {
        $response = $this->actingAs($this->admin())
            ->withSession(['locale' => 'ar'])
            ->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('bootstrap.rtl.min.css', false);
    }

    public function test_russian_dashboard_renders_operational_sections(): void
    {
        $response = $this->actingAs($this->admin())
            ->withSession(['locale' => 'ru'])
            ->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee('lang="ru"', false);
        $response->assertSee(__('dashboard.latest_payments'));
        $response->assertSee(__('dashboard.upcoming_exams'));
        $response->assertSee(__('dashboard.attendance_rate'));
        foreach (['invoiceChart', 'cashChart', 'teachersSpecializationChart', 'teachersStatusChart', 'topTeacherSubjectsChart'] as $canvasId) {
            $response->assertSee('id="' . $canvasId . '"', false);
        }
    }

    public function test_permission_gated_navigation_remains_conditional(): void
    {
        (new RolesAndPermissionsSeeder())->run();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $viewer = User::factory()->create();

        $adminNavigation = $this->actingAs($admin)->get(route('dashboard.index'));
        $adminNavigation->assertSee('Массовое начисление')->assertSee('Кассовые смены')->assertSee('Формы обучения');

        $this->actingAs($viewer);
        $viewerNavigation = view('layouts.dashboard')->with('content', '')->render();
        $this->assertStringNotContainsString('Массовое начисление', $viewerNavigation);
        $this->assertStringNotContainsString('Кассовые смены', $viewerNavigation);
        $this->assertStringNotContainsString('Формы обучения', $viewerNavigation);
    }

    #[DataProvider('existingPageRouteProvider')]
    public function test_existing_pages_still_render_inside_the_restored_shell(string $routeName): void
    {
        $response = $this->actingAs($this->admin())->get(route($routeName));

        $response->assertOk();
    }

    public static function existingPageRouteProvider(): array
    {
        return [
            ['dashboard.students.index'],
            ['dashboard.invoices.index'],
            ['dashboard.cash.ledger'],
            ['dashboard.cash.accounts'],
            ['dashboard.admin.audit.logs.index'],
            ['dashboard.attendance.index'],
            ['dashboard.report_cards.index'],
        ];
    }
}
