<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\ProjectEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectEventController extends Controller
{
    use ApiResponse;

    private const ALERT_TYPES = [
        'health_score_critical',
        'revision_risk_detected',
        'client_sentiment_declining',
        'deadline_at_risk',
        'ai_ticket_generated',
    ];

    // GET /api/v1/project-events/alerts
    public function alerts(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 15), 20);

        $events = ProjectEvent::with('project:id,name')
            ->whereIn('event_type', self::ALERT_TYPES)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (ProjectEvent $event) => [
                'id'           => $event->id,
                'event_type'   => $event->event_type,
                'project_id'   => $event->project_id,
                'project_name' => $event->project?->name,
                'metadata'     => $event->metadata ?? [],
                'created_at'   => $event->created_at,
            ]);

        return $this->success($events);
    }
}
