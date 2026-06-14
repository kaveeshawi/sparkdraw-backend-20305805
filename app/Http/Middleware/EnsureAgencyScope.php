<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Verifies every authenticated API user has a valid agency_id.
// This guarantees HasAgencyScope global scope will fire on every query.
// A missing agency_id would allow tenant data leaks — treat as critical.
class EnsureAgencyScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->agency_id) {
            return response()->json([
                'success' => false,
                'message' => 'No agency context found for this user.',
            ], 403);
        }

        return $next($request);
    }
}
