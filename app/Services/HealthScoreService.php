<?php

namespace App\Services;

use App\Models\HealthScore;
use App\Models\Project;
use App\Models\ProjectEvent;
use Carbon\Carbon;

class HealthScoreService
{
    public function __construct(private readonly AIService $aiService) {}

    public function computeAndSave(Project $project): ?HealthScore
    {
        $metrics = $project->getAIMetrics();
        $result  = $this->aiService->getHealthScore($metrics);

        if (empty($result['success']) || empty($result['data'])) {
            return null;
        }

        $data = $result['data'];

        $previous = HealthScore::where('project_id', $project->id)
            ->orderByDesc('computed_at')
            ->first();

        $computedAt = isset($data['computed_at'])
            ? Carbon::parse($data['computed_at'])
            : now();

        $healthScore = HealthScore::create([
            'agency_id'   => $project->agency_id,
            'project_id'  => $project->id,
            'score'       => (int) $data['score'],
            'flag'        => $data['flag'],
            'reasons'     => $data['reasons'] ?? [],
            'computed_at' => $computedAt,
        ]);

        if ($previous && $previous->flag !== 'red' && $data['flag'] === 'red') {
            ProjectEvent::log($project->agency_id, $project->id, 'health_score_critical', [
                'score'         => (int) $data['score'],
                'flag'          => 'red',
                'reasons'       => $data['reasons'] ?? [],
                'previous_flag' => $previous->flag,
            ]);
        }

        return $healthScore;
    }
}
