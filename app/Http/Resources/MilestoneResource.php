<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MilestoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tasks = $this->whenLoaded('tasks');

        $taskCount       = $this->relationLoaded('tasks') ? $this->tasks->count() : ($this->tasks_count ?? 0);
        $doneTasks       = $this->relationLoaded('tasks') ? $this->tasks->where('status', 'done')->count() : 0;
        $completionPct   = $taskCount > 0 ? (int) round(($doneTasks / $taskCount) * 100) : 0;
        $daysRemaining   = $this->due_date ? now()->diffInDays($this->due_date, false) : null;

        return [
            'id'             => $this->id,
            'title'          => $this->title,
            'due_date'       => $this->due_date?->toDateString(),
            'status'         => $this->status,
            'task_count'     => $taskCount,
            'completion_pct' => $completionPct,
            'days_remaining' => $daysRemaining !== null ? (int) $daysRemaining : null,
            'tasks'          => $this->when($this->relationLoaded('tasks'), $tasks),
            'created_at'     => $this->created_at,
        ];
    }
}
