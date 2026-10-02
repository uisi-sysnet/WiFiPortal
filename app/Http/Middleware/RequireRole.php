<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * role:viewer, role:user or role:admin on a route: the signed-in user needs at
 * least that role (viewer < user < admin). Otherwise: back to the dashboard with
 * a note, or 403 for requests that want JSON.
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->hasRole($role)) {
            return $next($request);
        }

        if ($request->expectsJson() || ! $user?->hasRole('viewer') || $request->routeIs('dashboard')) {
            abort(403, 'Your account does not have access to this page.');
        }

        return redirect()->route('dashboard')->with('denied', 'Your account ('.$user->roleLabel().') does not have access to that page. Ask an administrator if you need it.');
    }
}
