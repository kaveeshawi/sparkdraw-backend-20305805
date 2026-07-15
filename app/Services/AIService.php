<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * All AI calls go through this service — never call FastAPI directly from controllers.
 * Swap AI_PROVIDER in .env to change provider without touching any other code.
 * If FastAPI is unreachable, returns ['success' => false, 'message' => 'AI service unavailable'].
 */
class AIService
{
    private string $serviceUrl;

    public function __construct()
    {
        $this->serviceUrl = rtrim(config('ai.service_url', 'http://localhost:8001'), '/');
    }

    public function analyzeFeedback(string $feedbackText, string $projectType, int $projectId): array
    {
        return $this->callFastAPI('/analyze-feedback', [
            'feedback_text' => $feedbackText,
            'project_type'  => $projectType,
            'project_id'    => $projectId,
        ]);
    }

    public function getSentiment(string $text, int $projectId, int $messageId): array
    {
        return $this->callFastAPI('/sentiment', [
            'text'       => $text,
            'project_id' => $projectId,
            'message_id' => $messageId,
        ]);
    }

    public function getHealthScore(array $projectMetrics): array
    {
        return $this->callFastAPI('/health-score', $projectMetrics);
    }

    public function getUpsellSuggestion(array $projectData): array
    {
        return $this->callFastAPI('/upsell', $projectData);
    }

    public function estimateHours(string $title, string $description, string $projectType): array
    {
        return $this->callFastAPI('/estimate-hours', [
            'task_title'       => $title,
            'task_description' => $description,
            'project_type'     => $projectType,
        ]);
    }

    public function generateBrief(string $name, string $type, float $budget, int $durationWeeks, string $serviceDescription = ''): array
    {
        return $this->callFastAPI('/brief-generator', [
            'project_name'        => $name,
            'project_type'        => $type,
            'service_description' => $serviceDescription,
            'budget'              => $budget,
            'duration_weeks'      => $durationWeeks,
        ]);
    }

    public function generateDigest(string $projectName, string $clientName, string $eventsSummary): array
    {
        return $this->callFastAPI('/digest', [
            'project_name'  => $projectName,
            'client_name'   => $clientName,
            'events_summary' => $eventsSummary,
        ]);
    }

    private function callFastAPI(string $endpoint, array $data): array
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post("{$this->serviceUrl}{$endpoint}", $data);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("FastAPI returned non-2xx response", [
                'endpoint' => $endpoint,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);

            return [
                'success' => false,
                'message' => "AI service error: HTTP {$response->status()}",
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning("FastAPI unreachable — AI features degraded gracefully", [
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'AI service unavailable',
            ];
        } catch (\Throwable $e) {
            Log::error("Unexpected error calling FastAPI", [
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'AI service unavailable',
            ];
        }
    }
}
