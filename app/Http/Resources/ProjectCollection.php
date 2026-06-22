<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ProjectCollection extends ResourceCollection
{
    public $collects = ProjectResource::class;

    private array $summary;

    public function __construct($resource)
    {
        // Compute summary from the raw Eloquent collection before parent wraps it
        $this->summary = [
            'total'     => $resource->count(),
            'active'    => $resource->where('status', 'active')->count(),
            'completed' => $resource->where('status', 'completed')->count(),
            'at_risk'   => $resource->filter(
                fn($p) => $p->latestHealthScore &&
                          in_array($p->latestHealthScore->flag, ['amber', 'red'], true)
            )->count(),
        ];

        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'projects' => $this->collection,
            'summary'  => $this->summary,
        ];
    }
}
