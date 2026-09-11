<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\HealthScore;
use App\Models\Project;
use App\Services\HealthScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthScoreController extends Controller
{
    use ApiResponse;

    // GET /api/v1/health-scores
    public function index(Request $request): JsonResponse
    {
        $projects = Project::with([
            'client:id,company_name',
            'latestHealthScore',
        ])
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $data = $projects->map(function (Project $project) {
            $latest = $project->latestHealthScore;

            return [
                'project_id'   => $project->id,
                'project_name' => $project->name,
                'client_name'  => $project->client?->company_name,
                'score'        => $latest?->score,
                'flag'         => $latest?->flag,
                'reasons'      => $latest?->reasons ?? [],
                'computed_at'  => $latest?->computed_at,
            ];
        })->values();

        return $this->success($data);
    }

    // GET /api/v1/health-scores/{project}
    public function show(Request $request, Project $project): JsonResponse
    {
        $history = $project->healthScores()
            ->orderByDesc('computed_at')
            ->get()
            ->map(fn (HealthScore $hs) => [
                'id'          => $hs->id,
                'score'       => $hs->score,
                'flag'        => $hs->flag,
                'reasons'     => $hs->reasons ?? [],
                'computed_at' => $hs->computed_at,
            ]);

        return $this->success($history);
    }

    // GET /api/v1/health-scores/agency-average
    public function agencyAverage(Request $request): JsonResponse
    {
        $projects = Project::with('latestHealthScore')
            ->where('status', 'active')
            ->get();

        $scored = $projects->filter(fn (Project $p) => $p->latestHealthScore !== null);

        $greenCount = $scored->filter(fn (Project $p) => $p->latestHealthScore->flag === 'green')->count();
        $amberCount = $scored->filter(fn (Project $p) => $p->latestHealthScore->flag === 'amber')->count();
        $redCount   = $scored->filter(fn (Project $p) => $p->latestHealthScore->flag === 'red')->count();

        // null (not 0) when no scored projects — Overview must show "insufficient events", never a naked 0.
        $averageScore = $scored->isNotEmpty()
            ? round($scored->avg(fn (Project $p) => $p->latestHealthScore->score), 1)
            : null;

        $atRiskProjects = $projects
            ->filter(fn (Project $p) => $p->latestHealthScore?->flag === 'red' || $p->latestHealthScore?->flag === 'amber')
            ->map(fn (Project $p) => [
                'id'    => $p->id,
                'name'  => $p->name,
                'score' => $p->latestHealthScore->score,
                'flag'  => $p->latestHealthScore->flag,
            ])
            ->values();

        return $this->success([
            'average_score'    => $averageScore,
            'green_count'      => $greenCount,
            'amber_count'      => $amberCount,
            'red_count'        => $redCount,
            'at_risk_projects' => $atRiskProjects,
        ]);
    }

    // POST /api/v1/health-scores/compute/{project}
    public function compute(Request $request, Project $project, HealthScoreService $healthScoreService): JsonResponse
    {
        $result = $healthScoreService->computeAndSave($project);

        if (!$result) {
            return $this->error('Health score computation failed. AI service may be unavailable.', [], 503);
        }

        return $this->success([
            'project_id'  => $project->id,
            'score'       => $result->score,
            'flag'        => $result->flag,
            'reasons'     => $result->reasons,
            'computed_at' => $result->computed_at,
        ], 'Health score computed successfully.');
    }

    // POST /api/v1/health-scores/compute-all
    public function computeAll(Request $request, HealthScoreService $healthScoreService): JsonResponse
    {
        $projects = Project::where('status', 'active')->get();
        $computed = 0;

        foreach ($projects as $project) {
            if ($healthScoreService->computeAndSave($project)) {
                $computed++;
            }
        }

        return $this->success([
            'computed' => $computed,
            'total'    => $projects->count(),
        ], "Health scores recomputed for {$computed} projects.");
    }
}
