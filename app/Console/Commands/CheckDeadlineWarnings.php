<?php

namespace App\Console\Commands;

use App\Models\ProjectEvent;
use App\Models\Task;
use Illuminate\Console\Command;

class CheckDeadlineWarnings extends Command
{
    protected $signature = 'deadlines:check';

    protected $description = 'Warn about tasks at risk of missing deadlines in the next 5 days';

    public function handle(): int
    {
        $tasks = Task::withoutAgencyScope()
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [now()->startOfDay(), now()->addDays(5)->endOfDay()])
            ->where('status', '!=', 'done')
            ->whereNotNull('estimated_hours')
            ->where('estimated_hours', '>', 0)
            ->get();

        $warned = 0;

        foreach ($tasks as $task) {
            $burnRatio = $task->burn_ratio;

            if ($burnRatio <= 0.70) {
                continue;
            }

            $daysRemaining = (int) now()->startOfDay()->diffInDays($task->deadline, false);

            ProjectEvent::log($task->agency_id, $task->project_id, 'deadline_at_risk', [
                'task_title'      => $task->title,
                'deadline'        => $task->deadline->toDateString(),
                'burn_ratio'      => $burnRatio,
                'days_remaining'  => max(0, $daysRemaining),
                'project_id'      => $task->project_id,
                'assignee_id'     => $task->assignee_id,
            ]);

            $warned++;
        }

        $this->info("Logged {$warned} deadline at-risk warning(s).");

        return self::SUCCESS;
    }
}
