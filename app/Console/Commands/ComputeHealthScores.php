<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\HealthScoreService;
use Illuminate\Console\Command;

class ComputeHealthScores extends Command
{
    protected $signature = 'health-scores:compute';

    protected $description = 'Compute health scores for all active projects across all agencies';

    public function handle(HealthScoreService $healthScoreService): int
    {
        $projects = Project::withoutAgencyScope()
            ->where('status', 'active')
            ->get();

        $computed = 0;
        $redFlags = 0;

        foreach ($projects as $project) {
            $result = $healthScoreService->computeAndSave($project);

            if ($result) {
                $computed++;
                if ($result->flag === 'red') {
                    $redFlags++;
                }
            }
        }

        $this->info("Computed {$computed} health scores. {$redFlags} red flags.");

        return self::SUCCESS;
    }
}
