<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignee = $this->whenLoaded('assignee', function () {
            $name = $this->assignee?->name ?? '';
            // Avatar initials: first letter of each word, max 2
            $initials = collect(explode(' ', $name))
                ->filter()
                ->map(fn($w) => strtoupper($w[0]))
                ->take(2)
                ->implode('');

            return [
                'id'       => $this->assignee->id,
                'name'     => $name,
                'role'     => $this->assignee->role,
                'initials' => $initials,
            ];
        });

        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'description'      => $this->description,
            'status'           => $this->status,
            'priority'         => $this->priority,
            'estimated_hours'  => $this->estimated_hours,
            'actual_hours'     => $this->actual_hours,
            'burn_ratio'       => $this->burn_ratio,
            'is_overdue'       => $this->deadline && $this->status !== 'done'
                                    ? now()->isAfter($this->deadline)
                                    : false,
            'deadline'         => $this->deadline?->toDateString(),
            'milestone_id'     => $this->milestone_id,
            'project_id'       => $this->project_id,
            'project'          => $this->whenLoaded('project', fn () => [
                'id'    => $this->project->id,
                'name'  => $this->project->name,
                'color' => $this->project->color,
            ]),
            'assignee'         => $assignee,

            // Summary counts (set via withCount in controller)
            'time_log_count'   => $this->time_logs_count ?? null,

            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
