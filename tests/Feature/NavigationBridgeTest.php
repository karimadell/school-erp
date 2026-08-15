<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RolesAndPermissionsSeeder())->run();
    }

    public function test_authorized_user_sees_admin_link_on_dashboard_without_being_redirected(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee(route('filament.admin.pages.dashboard'), false)
            ->assertSee(__('menu.administration'));
    }

    public function test_dashboard_admin_link_uses_the_same_panel_access_rule_as_filament(): void
    {
        $adminPanel = Filament::getPanel('admin');

        foreach (['super-admin', 'admin', 'school-admin', 'accountant', 'cashier', 'reception', 'principal'] as $role) {
            $user = $this->userWithRole($role);

            $this->assertTrue($user->canAccessPanel($adminPanel));
            $this->actingAs($user)
                ->get(route('dashboard.index'))
                ->assertOk()
                ->assertSee(route('filament.admin.pages.dashboard'), false);
        }
    }

    public function test_unauthorized_and_inactive_users_do_not_see_admin_link(): void
    {
        $teacher = $this->userWithRole('teacher');
        $userWithoutRole = User::factory()->create(['is_active' => true]);
        $inactiveAdmin = $this->userWithRole('admin');
        $inactiveAdmin->update(['is_active' => false]);

        foreach ([$teacher, $userWithoutRole, $inactiveAdmin] as $user) {
            $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
            $this->actingAs($user);
            $this->assertStringNotContainsString(
                'href="'.route('filament.admin.pages.dashboard').'"',
                view('layouts.partials.shell-sidebar')->render(),
            );
        }
    }

    public function test_admin_brand_home_and_navigation_return_to_dashboard(): void
    {
        $panel = Filament::getPanel('admin');
        $dashboardUrl = route('dashboard.index');

        $this->assertSame($dashboardUrl, $panel->getHomeUrl());

        $dashboardItem = collect($panel->getNavigationItems())
            ->first(fn ($item): bool => $item->getUrl() === $dashboardUrl);

        $this->assertNotNull($dashboardItem);
        $this->assertSame(-100, $dashboardItem->getSort());

        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)
            ->get(route('filament.admin.pages.dashboard'))
            ->assertOk()
            ->assertSee($dashboardUrl, false)
            ->assertSee(__('menu.dashboard'));
    }

    public function test_admin_direct_access_remains_protected(): void
    {
        $this->get(route('filament.admin.pages.dashboard'))
            ->assertRedirect();

        $teacher = $this->userWithRole('teacher');
        $this->actingAs($teacher)
            ->get(route('filament.admin.pages.dashboard'))
            ->assertForbidden();
    }

    public function test_bridge_labels_render_in_russian_english_and_arabic(): void
    {
        $admin = $this->userWithRole('admin');

        foreach ([
            'ru' => ['Панель управления', 'Администрирование', 'ltr'],
            'en' => ['Dashboard', 'Administration', 'ltr'],
            'ar' => ['لوحة التحكم', 'الإدارة', 'rtl'],
        ] as $locale => [$dashboardLabel, $adminLabel, $direction]) {
            $this->withSession(['locale' => $locale])
                ->actingAs($admin)
                ->get(route('dashboard.index'))
                ->assertOk()
                ->assertSee($dashboardLabel)
                ->assertSee($adminLabel)
                ->assertSee('dir="'.$direction.'"', false);

            $this->withSession(['locale' => $locale])
                ->actingAs($admin)
                ->get(route('filament.admin.pages.dashboard'))
                ->assertOk()
                ->assertSee($dashboardLabel)
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
