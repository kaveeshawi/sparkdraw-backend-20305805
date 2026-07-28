<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Project;
use App\Models\ProjectEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RisksController extends Controller
{
    use ApiResponse;

    private const RISK_EVENT_TYPES = [
        'deadline_at_risk',
        'revision_risk_detected',
        'health_score_critical',
        'client_sentiment_declining',
    ];

    // GET /api/v1/projects/{project}/risks
    public function index(Request $request, Project $project): JsonResponse
    {
        $risks = ProjectEvent::where('project_id', $project->id)
            ->whereIn('event_type', self::RISK_EVENT_TYPES)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ProjectEvent $event) => [
                'id'         => $event->id,
                'event_type' => $event->event_type,
                'metadata'   => $event->metadata ?? [],
                'created_at' => $event->created_at,
            ]);

        return $this->success($risks);
    }
}
