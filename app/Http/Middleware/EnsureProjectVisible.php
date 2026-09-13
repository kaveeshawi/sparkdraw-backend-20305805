<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks members (without projects.view_all) from opening projects they are not
 * assigned to — covers nested /projects/{project}/* routes.
 */
class EnsureProjectVisible
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $project = $request->route('project');

        if (!$user || !$project instanceof Project) {
            return $next($request);
        }

        if (!$project->isVisibleTo($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found.',
            ], 404);
        }

        return $next($request);
    }
}
