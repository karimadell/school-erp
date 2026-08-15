<?php

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Filament\Pages\Dashboard;
use Filament\Support\Colors\Color;
use Filament\Enums\ThemeMode;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use App\Filament\Widgets\AttendanceChart;
use App\Filament\Widgets\CashLedger;
use App\Filament\Widgets\SchoolStats;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()

            ->id('admin')

            ->path('admin')

            ->login()

            // Keep the richer School ERP dashboard as the panel's home target.
            // The /admin dashboard below remains an operational Filament
            // landing page for users who enter the panel directly.
            ->homeUrl(fn (): string => route('dashboard.index'))

            // Modern UI v3: same accent color as the classic dashboard shell
            // (layouts/dashboard.blade.php's indigo-600 sidebar-active/accent).
            ->colors([
                'primary' => Color::Indigo,
            ])

            // Filament Visual Unification — Batch 1: default to light mode
            // instead of following the OS/browser color-scheme preference,
            // matching the dashboard shell (which has no dark mode at all).
            // The dark-mode toggle itself is untouched — users can still
            // switch to dark manually.
            ->defaultThemeMode(ThemeMode::Light)

            ->discoverResources(
                in: app_path('Filament/Resources'),
                for: 'App\\Filament\\Resources'
            )

            ->discoverPages(
                in: app_path('Filament/Pages'),
                for: 'App\\Filament\\Pages'
            )

            ->navigationGroups([
                'Учебный процесс',
                'Ученики',
                'Учителя и сотрудники',
                'Финансы',
                'Транспорт',
                'Администрирование',
            ])

            ->pages([
                Dashboard::class,
            ])

            ->widgets([
                SchoolStats::class,
                AttendanceChart::class,
                CashLedger::class,
            ])

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])

            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
