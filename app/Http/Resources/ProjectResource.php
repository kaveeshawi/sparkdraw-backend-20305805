<?php

namespace App\Http\Resources;

use App\Helpers\ProjectProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'type'            => $this->type,
            'description'     => $this->description,
            'status'          => $this->status,
            'priority'        => $this->priority,
            'color'           => $this->color,
            'budget'          => $this->budget,
            'estimated_hours' => $this->estimated_hours,
            'start_date'      => $this->start_date?->toDateString(),
            'end_date'        => $this->end_date?->toDateString(),
            'created_at'      => $this->created_at,
            'client_id'       => $this->client_id,

            // Client
            'client' => $this->whenLoaded('client', fn() => [
                'id'           => $this->client->id,
                'company_name' => $this->client->company_name,
            ]),

            // Health score — latest computed snapshot
            'health_score' => $this->whenLoaded('latestHealthScore', fn() => $this->latestHealthScore ? [
                'score'       => $this->latestHealthScore->score,
                'flag'        => $this->latestHealthScore->flag,
                'reasons'     => $this->latestHealthScore->reasons,
                'computed_at' => $this->latestHealthScore->computed_at,
            ] : null),

            // Progress
            'progress_pct' => ProjectProgress::calculate($this->resource),

            // Task counts — populated from withCount() on index, or loaded tasks on show()
            'task_counts' => $this->taskCounts(),

            // Milestone count
            'milestone_count' => $this->when(
                isset($this->milestones_count),
                $this->milestones_count ?? 0,
            ),

            // Next incomplete (or earliest) milestone for project cards
            'next_milestone' => $this->when(
                $this->relationLoaded('milestones'),
                function () {
                    $milestones = $this->milestones;
                    if ($milestones->isEmpty()) {
                        return null;
                    }

                    $next = $milestones->first(
                        fn ($m) => ! in_array($m->status, ['completed', 'done'], true),
                    ) ?? $milestones->sortBy('due_date')->first();

                    if (! $next) {
                        return null;
                    }

                    return [
                        'id'       => $next->id,
                        'title'    => $next->title,
                        'due_date' => $next->due_date?->toDateString(),
                        'status'   => $next->status,
                    ];
                },
            ),

            // Full milestone list (show() only — still available when loaded)
            'milestones' => MilestoneResource::collection($this->whenLoaded('milestones')),

            // Team members — union of explicitly assigned members and task assignees
            'team_members' => $this->when(
                $this->relationLoaded('tasks') || $this->relationLoaded('teamMembers'),
                function () {
                    $assigned = $this->relationLoaded('teamMembers')
                        ? $this->resource->teamMembers
                        : collect();

                    $fromTasks = $this->relationLoaded('tasks')
                        ? $this->resource->tasks->pluck('assignee')->filter()
                        : collect();

                    return $assigned->concat($fromTasks)
                        ->unique('id')
                        ->values()
                        ->take(8)
                        ->map(fn ($u) => [
                            'id'          => $u->id,
                            'name'        => $u->name,
                            'role'        => $u->role,
                            'avatar_path' => $u->avatar_path,
                        ]);
                },
            ),
        ];
    }

    private function taskCounts(): array
    {
        // withCount() columns (set on index) are always accurate — the eager-loaded
        // `tasks` relation on index() is filtered to assignee_id IS NOT NULL for the
        // team-members list, so it must never be used to derive totals here.
        if (isset($this->tasks_count)) {
            return [
                'total'       => $this->tasks_count ?? 0,
                'todo'        => 0,
                'in_progress' => 0,
                'in_review'   => 0,
                'done'        => $this->done_tasks_count ?? 0,
            ];
        }

        // show() loads the full (unfiltered) tasks relation — safe to use directly.
        if ($this->relationLoaded('tasks')) {
            $tasks = $this->resource->tasks;
            return [
                'total'       => $tasks->count(),
                'todo'        => $tasks->where('status', 'todo')->count(),
                'in_progress' => $tasks->where('status', 'in_progress')->count(),
                'in_review'   => $tasks->where('status', 'in_review')->count(),
                'done'        => $tasks->where('status', 'done')->count(),
            ];
        }

        return ['total' => 0, 'todo' => 0, 'in_progress' => 0, 'in_review' => 0, 'done' => 0];
    }
}
