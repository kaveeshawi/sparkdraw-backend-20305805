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

    public function generateBrief(
        string $name,
        string $type,
        float $budget,
        int $durationWeeks,
        string $serviceDescription = '',
        string $requirements = '',
        ?int $defaultHours = null,
        ?float $defaultBudget = null,
        array $suggestedRoles = [],
        string $packageName = '',
        string $includes = '',
        array $availablePackages = [],
        bool $lockCustomPackage = false,
    ): array {
        $payload = [
            'project_name'         => $name,
            'project_type'         => $type,
            'service_description'  => $serviceDescription,
            'package_name'         => $packageName,
            'includes'             => $includes,
            'requirements'         => $requirements,
            'budget'               => $budget,
            'duration_weeks'       => $durationWeeks,
            'suggested_roles'      => array_values($suggestedRoles),
            'available_packages'   => array_values($availablePackages),
            'lock_custom_package'  => $lockCustomPackage,
        ];
        if ($defaultHours !== null) {
            $payload['default_hours'] = $defaultHours;
        }
        if ($defaultBudget !== null) {
            $payload['default_budget'] = $defaultBudget;
        }

        return $this->callFastAPI('/brief-generator', $payload);
    }

    public function generateDigest(string $projectName, string $clientName, string $eventsSummary): array
    {
        return $this->callFastAPI('/digest', [
            'project_name'  => $projectName,
            'client_name'   => $clientName,
            'events_summary' => $eventsSummary,
        ]);
    }

    /**
     * Assistive payment reminder draft. Tries FastAPI first; falls back to a local template
     * so Finance still works when the AI microservice is offline.
     */
    public function generateInvoiceReminder(array $payload): array
    {
        $result = $this->callFastAPI('/invoice-reminder', $payload);

        if (!empty($result['success'])) {
            return $result;
        }

        $client = $payload['client_name'] ?? 'there';
        $number = $payload['invoice_number'] ?? 'your invoice';
        $amount = $payload['amount_label'] ?? 'the outstanding balance';
        $due = $payload['due_date'] ?? null;
        $project = $payload['project_name'] ?? 'your project';
        $agency = $payload['agency_name'] ?? 'our team';
        $status = $payload['status'] ?? 'sent';

        $dueLine = $due
            ? ($status === 'overdue'
                ? "This invoice was due on {$due} and remains unpaid."
                : "Payment is due by {$due}.")
            : 'Please arrange payment at your earliest convenience.';

        $subject = $status === 'overdue'
            ? "Overdue invoice {$number} — friendly reminder"
            : "Reminder: invoice {$number} for {$project}";

        $body = "Hi {$client},\n\n"
            . "I hope you are well. This is a friendly reminder regarding invoice {$number} "
            . "({$amount}) for {$project}.\n\n"
            . "{$dueLine}\n\n"
            . "You can pay securely through your client portal, or reply to this message if you have any questions.\n\n"
            . "Thank you,\n{$agency}";

        return [
            'success' => true,
            'data' => [
                'subject' => $subject,
                'body'    => $body,
                'source'  => 'fallback',
            ],
        ];
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
