<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // Trust the platform's own edge proxy (e.g. Railway) so Laravel
        // correctly detects HTTPS/client IP from X-Forwarded-* headers.
        // The proxy is the first hop in front of the container, not an
        // arbitrary untrusted client, so trusting it here is safe.
        $middleware->trustProxies(at: '*');

        // WEB (language middleware)
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        // API
        $middleware->group('api', [
            EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            SubstituteBindings::class,
        ]);

        // ALIASES
        $middleware->alias([
            'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
            'auth:sanctum' => \Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,

            // ✅ permission middleware (your custom one)
            'permission' => \App\Http\Middleware\CheckPermission::class,

            // Spatie's role-check middleware, used by routes/web.php's
            // admin group (role:admin) — was previously unregistered,
            // causing every request there to throw a "Target class [role]
            // does not exist" error.
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();

// Russian-first UI cleanup: all translation files (including the
// vendor:published Filament chrome translations under lang/vendor/filament)
// live under lang/, the Laravel 9+ default. Without this, Application's
// default langPath resolution falls back to resources/lang instead, which
// silently made every file under lang/ (including Filament's own published
// `ru` strings) dead — never loaded, for any locale.
$app->useLangPath(base_path('lang'));

return $app;