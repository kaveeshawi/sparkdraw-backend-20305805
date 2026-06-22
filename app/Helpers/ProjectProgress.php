<?php

namespace App\Helpers;

use App\Models\Project;

class ProjectProgress
{
    /**
     * Calculate project completion as a percentage of done tasks vs total tasks.
     * Returns 0 when no tasks exist (not 100%, because nothing is actually done).
     */
    public static function calculate(Project $project): int
    {
        // withCount() columns (set by ProjectController::index) are always accurate.
        // The eager-loaded `tasks` relation on index() is filtered to assignee_id
        // IS NOT NULL (for the team-members list), so it must not be used here —
        // that would undercount projects with unassigned tasks.
        if (isset($project->tasks_count)) {
            $total = $project->tasks_count ?? 0;
            $done  = $project->done_tasks_count ?? 0;

            return $total === 0 ? 0 : (int) round(($done / $total) * 100);
        }

        // show() loads the full (unfiltered) tasks relation — safe to use directly.
        if ($project->relationLoaded('tasks')) {
            $tasks = $project->tasks;
            if ($tasks->isEmpty()) {
                return 0;
            }
            $done = $tasks->where('status', 'done')->count();
            return (int) round(($done / $tasks->count()) * 100);
        }

        return 0;
    }
}
