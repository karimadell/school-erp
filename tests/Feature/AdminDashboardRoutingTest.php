<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Enums\ThemeMode;
use Filament\Pages\Dashboard;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Enums\Width;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_uses_the_school_erp_dashboard_as_its_home(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame(route('dashboard.index'), $panel->getHomeUrl());
        $this->assertContains(Dashboard::class, $panel->getPages());
        $this->assertNotContains(AccountWidget::class, $panel->getWidgets());
        $this->assertNotContains(FilamentInfoWidget::class, $panel->getWidgets());
        $this->assertContains(\App\Filament\Widgets\SchoolStats::class, $panel->getWidgets());
        $this->assertContains(\App\Filament\Widgets\AttendanceChart::class, $panel->getWidgets());
        $this->assertContains(\App\Filament\Widgets\CashLedger::class, $panel->getWidgets());
    }

    public function test_admin_panel_uses_the_shared_erp_visual_configuration(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertNull($panel->getViteTheme());
        $this->assertSame(
            realpath(base_path('vendor/filament/filament/dist/theme.css')),
            realpath($panel->getTheme()->getPath()),
        );
        $this->assertSame(
            'erp-admin-theme',
            collect(FilamentAsset::getStyles())->firstWhere(fn ($asset): bool => $asset->getId() === 'erp-admin-theme')?->getId(),
        );
        $this->assertSame(Width::Full, $panel->getMaxContentWidth());
        $this->assertSame('16.5rem', $panel->getSidebarWidth());
        $this->assertSame(ThemeMode::Light, $panel->getDefaultThemeMode());
        $this->assertFalse($panel->hasDarkMode());
    }

    public function test_admin_entry_point_renders_only_operational_erp_widgets(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('/css/filament/filament/app.css', false)
            ->assertSee('/build/assets/theme-', false)
            ->assertSee('Студенты')
            ->assertDontSee('filament v', false)
            ->assertDontSee('github.com/filamentphp/filament', false);
    }

    public function test_admin_panel_renders_right_to_left_for_arabic(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->withSession(['locale' => 'ar'])
            ->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('dir="rtl"', false);
    }

    public function test_critical_admin_resources_render_with_filament_base_and_erp_styles(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        foreach (['/admin/classes', '/admin/roles', '/admin/permissions'] as $path) {
            $this->actingAs($admin)
                ->get($path)
                ->assertOk()
                ->assertSee('/css/filament/filament/app.css', false)
                ->assertSee('/build/assets/theme-', false);
        }
    }
}
