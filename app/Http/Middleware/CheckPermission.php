<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = auth()->user();

        if (! $user || ! $user->can($permission)) {
            abort(403, 'ليس لديك صلاحية');
        }

        return $next($request);
    }
}