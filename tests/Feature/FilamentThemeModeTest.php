<?php

namespace Tests\Feature;

use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Teacher\Pages\TeacherClasses;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filament Visual Unification — Batch 1: both panels now default to light
 * mode instead of following the OS/browser color-scheme preference
 * (Filament's own default is ThemeMode::System), matching the dashboard
 * shell, which has no dark mode at all. The dark-mode toggle itself is
 * untouched.
 */
class FilamentThemeModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_defaults_to_light_theme_mode(): void
    {
        $this->assertSame(
            ThemeMode::Light,
            Filament::getPanel('admin')->getDefaultThemeMode()
        );
    }

    public function test_teacher_panel_defaults_to_light_theme_mode(): void
    {
        $this->assertSame(
            ThemeMode::Light,
            Filament::getPanel('teacher')->getDefaultThemeMode()
        );
    }

    public function test_admin_panel_still_renders_successfully(): void
    {
        (new RolesAndPermissionsSeeder())->run();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)->test(ListRoles::class)->assertSuccessful();
    }

    public function test_teacher_panel_still_renders_successfully(): void
    {
        (new RolesAndPermissionsSeeder())->run();
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');

        Livewire::actingAs($teacher)->test(TeacherClasses::class)->assertSuccessful();
    }
}
