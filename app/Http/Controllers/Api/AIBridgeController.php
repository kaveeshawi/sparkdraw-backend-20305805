<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\HealthScore;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\UpsellSuggestion;
use App\Services\AIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bridge between the Laravel API and the FastAPI AI microservice.
 * Never call OpenAI or FastAPI directly from any other controller — go through here + AIService.
 */
class AIBridgeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AIService $aiService) {}

    // POST /api/v1/ai/analyze-feedback
    public function analyzeFeedback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'feedback_text' => ['required', 'string', 'min:10', 'max:2000'],
            'project_type'  => ['required', 'string', 'max:100'],
            'project_id'    => ['required', 'integer', 'exists:projects,id'],
        ]);

        $result = $this->aiService->analyzeFeedback(
            $validated['feedback_text'],
            $validated['project_type'],
            $validated['project_id'],
        );

        if (empty($result['success'])) {
            return $this->error($result['message'] ?? 'AI service unavailable', [], 503);
        }

        ProjectEvent::log($request->user()->agency_id, $validated['project_id'], 'ai_bridge_called', [
            'endpoint' => 'analyze-feedback',
        ]);

        return $this->success($result['data'] ?? null);
    }

    // POST /api/v1/ai/sentiment
    public function getSentiment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text'       => ['required', 'string', 'min:1'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'message_id' => ['required', 'integer'],
        ]);

        $result = $this->aiService->getSentiment(
            $validated['text'],
            $validated['project_id'],
            $validated['message_id'],
        );

        if (empty($result['success'])) {
            return $this->error($result['message'] ?? 'AI service unavailable', [], 503);
        }

        ProjectEvent::log($request->user()->agency_id, $validated['project_id'], 'ai_bridge_called', [
            'endpoint' => 'sentiment',
        ]);

        return $this->success($result['data'] ?? null);
    }

    // POST /api/v1/ai/health-score
    public function getHealthScore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ]);

        $project = Project::findOrFail($validated['project_id']);
        $metrics = $project->getAIMetrics();

        $result = $this->aiService->getHealthScore($metrics);

        if (empty($result['success'])) {
            return $this->error($result['message'] ?? 'AI service unavailable', [], 503);
        }

        $data = $result['data'];

        $healthScore = HealthScore::create([
            'agency_id'   => $request->user()->agency_id,
            'project_id'  => $project->id,
            'score'       => $data['score'],
            'flag'        => $data['flag'],
            'reasons'     => $data['reasons'] ?? [],
            'computed_at' => $data['computed_at'] ?? now(),
        ]);

        ProjectEvent::log($request->user()->agency_id, $project->id, 'ai_bridge_called', [
            'endpoint' => 'health-score',
        ]);

        return $this->success($healthScore);
    }

    // POST /api/v1/ai/upsell
    public function getUpsell(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ]);

        $project = Project::with('latestHealthScore')->findOrFail($validated['project_id']);
        $payload = $project->getUpsellMetrics();

        $result = $this->aiService->getUpsellSuggestion($payload);

        if (empty($result['success'])) {
            return $this->error($result['message'] ?? 'AI service unavailable', [], 503);
        }

        $data = $result['data'];
        $suggestion = null;

        $confidence = (float) ($data['confidence'] ?? 0);
        $upsellReady = !empty($data['upsell_ready']) && $confidence >= 0.55;

        if ($upsellReady) {
            $serviceType = $data['service'] ?? match ($data['tier'] ?? 'medium') {
                'high'   => 'upsell_recommended',
                'medium' => 'upsell_recommended',
                default  => 'upsell_recommended',
            };

            $suggestion = UpsellSuggestion::create([
                'agency_id'     => $request->user()->agency_id,
                'project_id'    => $project->id,
                'service_type'  => $serviceType,
                'confidence'    => $confidence,
                'admin_status'  => 'pending',
                'client_status' => 'hidden',
            ]);
        }

        ProjectEvent::log($request->user()->agency_id, $project->id, 'ai_bridge_called', [
            'endpoint' => 'upsell',
        ]);

        return $this->success([
            'suggestion' => $suggestion,
            'raw'        => $data,
        ]);
    }

    // POST /api/v1/ai/estimate-hours
    public function estimateHours(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_title'       => ['required', 'string', 'max:255'],
            'task_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'project_type'     => ['required', 'string', 'max:100'],
        ]);

        $result = $this->aiService->estimateHours(
            $validated['task_title'],
            $validated['task_description'] ?? '',
            $validated['project_type'],
        );

        if (empty($result['success'])) {
            return $this->error($result['message'] ?? 'AI service unavailable', [], 503);
        }

        return $this->success($result['data'] ?? null);
    }

    // POST /api/v1/ai/brief-generator
    public function generateBrief(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_name'        => ['required', 'string', 'max:200'],
            'project_type'        => ['required', 'string', 'max:100'],
            'service_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'budget'              => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'duration_weeks'      => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $result = $this->aiService->generateBrief(
            $validated['project_name'],
            $validated['project_type'],
            (float) ($validated['budget'] ?? 0),
            (int) ($validated['duration_weeks'] ?? 4),
            $validated['service_description'] ?? '',
        );

        if (empty($result['success'])) {
            return $this->error($result['message'] ?? 'AI service unavailable', [], 503);
        }

        return $this->success($result['data'] ?? null);
    }

    // POST /api/v1/ai/digest
    public function generateDigest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ]);

        $project = Project::with('client:id,company_name')->findOrFail($validated['project_id']);

        $events = ProjectEvent::where('project_id', $project->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('created_at')
            ->get(['event_type', 'metadata', 'created_at'])
            ->toArray();

        $result = $this->aiService->generateDigest(
            $project->name,
            $project->client->company_name ?? 'Client',
            json_encode($events),
        );

        if (empty($result['success'])) {
            return $this->error($result['message'] ?? 'AI service unavailable', [], 503);
        }

        ProjectEvent::log($request->user()->agency_id, $project->id, 'ai_bridge_called', [
            'endpoint' => 'digest',
        ]);

        return $this->success($result['data'] ?? null);
    }
}
