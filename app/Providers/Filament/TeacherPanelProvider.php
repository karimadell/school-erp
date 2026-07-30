<?php

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Filament\Pages\Dashboard;
use Filament\Support\Colors\Color;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;

class TeacherPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('teacher')
            ->path('teacher')
            ->login()

            // Modern UI v3: same accent color as the classic dashboard shell
            // and the Admin panel, for one consistent color system.
            ->colors([
                'primary' => Color::Indigo,
            ])

            ->discoverPages(
                in: app_path('Filament/Teacher/Pages'),
                for: 'App\\Filament\\Teacher\\Pages'
            )

            ->pages([
                Dashboard::class,
            ])

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
            ])

            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}