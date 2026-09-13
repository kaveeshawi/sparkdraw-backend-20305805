<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Usage in routes: ->middleware('permission:financials.view')
// Layers on top of `role:` route groups — admin and client are untouched by this
// system (admin always has full access; client access is governed separately by
// the client-portal routes/controller scoping). Only pm/member are actually gated.
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this resource.',
            ], 403);
        }

        if (in_array($user->role, ['admin', 'client'], true)) {
            return $next($request);
        }

        if (!$user->hasPermission($key)) {
            return response()->json([
                'success' => false,
                'message' => 'Your role does not have access to this feature.',
            ], 403);
        }

        return $next($request);
    }
}
