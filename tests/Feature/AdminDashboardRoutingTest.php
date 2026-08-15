<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
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

    public function test_admin_entry_point_renders_only_operational_erp_widgets(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Студенты')
            ->assertDontSee('filament v', false)
            ->assertDontSee('github.com/filamentphp/filament', false);
    }
}
