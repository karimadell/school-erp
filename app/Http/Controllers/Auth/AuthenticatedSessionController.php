<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $user = $request->user();

        if ($user->canAccessPanel(\Filament\Facades\Filament::getPanel('teacher'))) {
            return redirect()->route('filament.teacher.pages.dashboard');
        }

        if ($user->canAccessAdministrativePortal()) {
            return redirect()->route('dashboard.index');
        }

        $this->logoutInvalidAccount($request);

        throw ValidationException::withMessages([
            'email' => 'Учётная запись преподавателя не активирована. Обратитесь к администратору.',
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    protected function logoutInvalidAccount(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
