<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TaskCollection extends ResourceCollection
{
    public $collects = TaskResource::class;

    private bool $grouped;

    public function __construct($resource, bool $grouped = false)
    {
        $this->grouped = $grouped;
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        if ($this->grouped) {
            // Kanban board: 4 columns keyed by status
            $byStatus = $this->collection->groupBy(fn($r) => $r->resource->status);

            return [
                'todo'        => $byStatus->get('todo', collect())->values(),
                'in_progress' => $byStatus->get('in_progress', collect())->values(),
                'in_review'   => $byStatus->get('in_review', collect())->values(),
                'done'        => $byStatus->get('done', collect())->values(),
            ];
        }

        return [
            'tasks' => $this->collection,
            'total' => $this->collection->count(),
        ];
    }
}
