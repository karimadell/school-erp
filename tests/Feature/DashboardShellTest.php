<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Modern UI Foundation — Batch 1: resources/views/layouts/dashboard.blade.php
 * was rebuilt around a new sidebar/topbar shell (layouts/partials/shell-*),
 * while every dashboard/* content view keeps rendering through the same
 * unchanged @yield('content'). This guards that the new shell renders, that
 * the requested navigation groups are present, that known-incomplete
 * modules stay hidden, and that a handful of representative existing pages
 * still render correctly inside the new shell.
 */
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

    public function test_dashboard_renders_the_new_shell_with_expected_navigation(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee('ui2-shell', false);
        $response->assertSee('ui2-sidebar', false);
        $response->assertSee('data-sidebar-collapse-toggle', false);

        foreach ([
            'Панель управления', 'Структура школы', 'Предметы', 'Посещаемость',
            'Табели успеваемости', 'Все ученики', 'Зачисление', 'Учителя',
            'Счета', 'Услуги', 'Касса', 'Кассовые счета', 'Расходы',
            'Финансовые отчёты', 'Пользователи', 'Роли и разрешения', 'Журнал действий',
        ] as $expected) {
            $response->assertSee($expected);
        }
    }

    public function test_incomplete_modules_stay_hidden_from_the_new_sidebar(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard.index'));

        $response->assertOk();

        foreach (['Экзамены', 'Транспорт', 'Родители', 'Документы', 'Сотрудники', 'Резервные копии', 'Настройки школы'] as $hidden) {
            $response->assertDontSee($hidden);
        }
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

    #[DataProvider('existingPageRouteProvider')]
    public function test_existing_pages_still_render_inside_the_new_shell(string $routeName): void
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
