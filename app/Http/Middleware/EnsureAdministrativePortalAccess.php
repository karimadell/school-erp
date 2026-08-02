<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdministrativePortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->canAccessAdministrativePortal()) {
            return $next($request);
        }

        if ($user?->isActive() && $user->canAccessPanel(\Filament\Facades\Filament::getPanel('teacher'))) {
            return redirect()->route('filament.teacher.pages.dashboard');
        }

        if ($user) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Учётная запись преподавателя не активирована. Обратитесь к администратору.',
            ]);
        }

        abort(403);
    }
}
