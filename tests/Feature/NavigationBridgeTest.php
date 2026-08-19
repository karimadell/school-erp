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

    /**
     * Pre-UAT fix (independent review, Fix 8): this used to assert the
     * dashboard page contains route('filament.admin.pages.dashboard')
     * ("http://.../admin") via a broad substring match. Because
     * /admin/teacher-assignments, /admin/teacher-salaries, /admin/buses
     * etc. all contain that same "/admin" substring, the assertion kept
     * passing after the Administration item was repointed at
     * dashboard.administration.index — it was being satisfied by unrelated
     * links, not by the Administration item it was meant to cover. This now
     * asserts the Administration item's exact, current destination.
     */
    public function test_authorized_user_sees_the_administration_link_pointing_at_the_dashboard_hub(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee(route('dashboard.administration.index'), false)
            ->assertSee(__('menu.administration'));
    }

    /**
     * Pre-UAT fix (Fix 8): same substring-matching problem as above, and the
     * same fix — assert the exact dashboard.administration.index href
     * rather than a "/admin" substring. The underlying claim the test name
     * makes ("same panel access rule as Filament") still holds: the
     * Administration item's visibility is gated on
     * canAccessPanel($adminPanel) (see shell-sidebar.blade.php), which uses
     * the identical administrativeRoles()+active check Filament's own panel
     * access uses — only the rendered destination changed.
     */
    public function test_dashboard_admin_link_uses_the_same_panel_access_rule_as_filament(): void
    {
        $adminPanel = Filament::getPanel('admin');

        foreach (['super-admin', 'admin', 'school-admin', 'accountant', 'cashier', 'reception', 'principal'] as $role) {
            $user = $this->userWithRole($role);

            $this->assertTrue($user->canAccessPanel($adminPanel));
            $this->actingAs($user)
                ->get(route('dashboard.index'))
                ->assertOk()
                ->assertSee(route('dashboard.administration.index'), false);
        }
    }

    public function test_unauthorized_and_inactive_users_do_not_see_the_administration_link(): void
    {
        $teacher = $this->userWithRole('teacher');
        $userWithoutRole = User::factory()->create(['is_active' => true]);
        $inactiveAdmin = $this->userWithRole('admin');
        $inactiveAdmin->update(['is_active' => false]);

        foreach ([$teacher, $userWithoutRole, $inactiveAdmin] as $user) {
            $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
            $this->actingAs($user);
            $this->assertStringNotContainsString(
                'href="'.route('dashboard.administration.index').'"',
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
