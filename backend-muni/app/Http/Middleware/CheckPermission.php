<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (!auth()->check()) {
            return redirect('login');
        }

        if (!auth()->user()->hasPermissionTo($permission)) {
            abort(403, 'No tienes permiso para acceder a esta funcionalidad.');
        }

        return $next($request);
    }
}
