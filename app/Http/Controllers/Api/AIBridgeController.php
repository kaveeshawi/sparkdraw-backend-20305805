<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\HealthScore;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\UpsellSuggestion;
use App\Services\AiCreditService;
use App\Services\AIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Bridge between the Laravel API and the FastAPI AI microservice.
 * Never call OpenAI or FastAPI directly from any other controller — go through here + AIService.
 */
class AIBridgeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AIService $aiService,
        private readonly AiCreditService $credits,
    ) {}

    private function chargeCredits(Request $request, string $feature, array $metadata = []): ?JsonResponse
    {
        try {
            $this->credits->charge($request->user(), $feature, $metadata);

            return null;
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), [
                'feature'   => $feature,
                'cost'      => $this->credits->costFor($feature),
                'remaining' => $this->credits->remaining($request->user()),
            ], 402);
        }
    }

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

        if ($denied = $this->chargeCredits($request, 'analyze_feedback', [
            'project_id' => $validated['project_id'],
        ])) {
            return $denied;
        }

        ProjectEvent::log($request->user()->agency_id, $validated['project_id'], 'ai_bridge_called', [
            'endpoint' => 'analyze-feedback',
            'credits'  => $this->credits->costFor('analyze_feedback'),
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

        if ($denied = $this->chargeCredits($request, 'sentiment', [
            'project_id' => $validated['project_id'],
            'message_id' => $validated['message_id'],
        ])) {
            return $denied;
        }

        ProjectEvent::log($request->user()->agency_id, $validated['project_id'], 'ai_bridge_called', [
            'endpoint' => 'sentiment',
            'credits'  => $this->credits->costFor('sentiment'),
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

        if ($denied = $this->chargeCredits($request, 'health_score', [
            'project_id' => $project->id,
        ])) {
            return $denied;
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
            'credits'  => $this->credits->costFor('health_score'),
        ]);

        return $this->success($healthScore);
    }

    // POST /api/v1/ai/upsell
    public function getUpsell(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'force'      => ['sometimes', 'boolean'],
        ]);

        $project = Project::with('latestHealthScore')->findOrFail($validated['project_id']);
        $payload = $project->getUpsellMetrics();

        $result = $this->aiService->getUpsellSuggestion($payload);

        if (empty($result['success'])) {
            return $this->error($result['message'] ?? 'AI service unavailable', [], 503);
        }

        if ($denied = $this->chargeCredits($request, 'upsell', [
            'project_id' => $project->id,
        ])) {
            return $denied;
        }

        $data = $result['data'] ?? [];
        $confidence = (float) ($data['confidence'] ?? 0);
        $upsellReady = !empty($data['upsell_ready']) && $confidence >= 0.55;
        // Project AI tab / explicit generate always persists the trained-model score.
        $force = array_key_exists('force', $validated)
            ? (bool) $validated['force']
            : true;

        $suggestion = null;
        $suggestions = [];
        if ($upsellReady || $force) {
            $options = $data['options'] ?? null;
            if (!is_array($options) || count($options) === 0) {
                $fallbackService = $data['service']
                    ?? match ($data['tier'] ?? 'medium') {
                        'high'  => 'premium_retainer',
                        'low'   => 'nurture_followup',
                        default => 'upsell_recommended',
                    };
                $options = [[
                    'service'    => $fallbackService,
                    'confidence' => $confidence,
                    'rank'       => 1,
                ]];
            }

            // Re-run replaces pending (not-yet-sent) options; keep anything already shown to client.
            UpsellSuggestion::withoutGlobalScope('agency')
                ->where('agency_id', $request->user()->agency_id)
                ->where('project_id', $project->id)
                ->where('admin_status', 'pending')
                ->where('client_status', 'hidden')
                ->delete();

            foreach (array_slice($options, 0, 3) as $opt) {
                $svc = (string) ($opt['service'] ?? 'upsell_recommended');
                $conf = (float) ($opt['confidence'] ?? $confidence);

                $row = UpsellSuggestion::create([
                    'agency_id'     => $request->user()->agency_id,
                    'project_id'    => $project->id,
                    'service_type'  => $svc,
                    'confidence'    => $conf,
                    'admin_status'  => 'pending',
                    'client_status' => 'hidden',
                ]);
                $suggestions[] = $row;
            }

            $suggestion = $suggestions[0] ?? null;
        }

        ProjectEvent::log($request->user()->agency_id, $project->id, 'ai_bridge_called', [
            'endpoint'   => 'upsell',
            'credits'    => $this->credits->costFor('upsell'),
            'confidence' => $confidence,
            'ready'      => $upsellReady,
            'forced'     => $force && !$upsellReady,
            'options'    => count($suggestions),
        ]);

        return $this->success([
            'suggestion'  => $suggestion,
            'suggestions' => $suggestions,
            'raw'         => $data,
            'metrics'     => $payload,
            'applied'     => $suggestion !== null,
            'ready'       => $upsellReady,
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

        if ($denied = $this->chargeCredits($request, 'estimate_hours')) {
            return $denied;
        }

        return $this->success($result['data'] ?? null);
    }

    // POST /api/v1/ai/brief-generator
    public function generateBrief(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_name'        => ['required', 'string', 'max:200'],
            'project_type'        => ['required', 'string', 'max:100'],
            'service_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'package_name'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'includes'            => ['sometimes', 'nullable', 'string', 'max:4000'],
            'requirements'        => ['sometimes', 'nullable', 'string', 'max:4000'],
            'budget'              => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'duration_weeks'      => ['sometimes', 'nullable', 'integer', 'min:1'],
            'default_hours'       => ['sometimes', 'nullable', 'integer', 'min:1'],
            'default_budget'      => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'suggested_roles'     => ['sometimes', 'nullable', 'array'],
            'suggested_roles.*'   => ['string', 'max:100'],
            'available_packages'  => ['sometimes', 'nullable', 'array', 'max:20'],
            'available_packages.*.name' => ['required_with:available_packages', 'string', 'max:100'],
            'available_packages.*.includes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'available_packages.*.duration_hours' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'available_packages.*.price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'available_packages.*.suggested_roles' => ['sometimes', 'nullable', 'array'],
            'available_packages.*.suggested_roles.*' => ['string', 'max:100'],
            'lock_custom_package' => ['sometimes', 'boolean'],
        ]);

        $result = $this->aiService->generateBrief(
            $validated['project_name'],
            $validated['project_type'],
            (float) ($validated['budget'] ?? 0),
            (int) ($validated['duration_weeks'] ?? 4),
            $validated['service_description'] ?? '',
            $validated['requirements'] ?? '',
            isset($validated['default_hours']) ? (int) $validated['default_hours'] : null,
            isset($validated['default_budget']) ? (float) $validated['default_budget'] : null,
            $validated['suggested_roles'] ?? [],
            $validated['package_name'] ?? '',
            $validated['includes'] ?? '',
            $validated['available_packages'] ?? [],
            (bool) ($validated['lock_custom_package'] ?? false),
        );

        if (empty($result['success'])) {
            return $this->error($result['message'] ?? 'AI service unavailable', [], 503);
        }

        if ($denied = $this->chargeCredits($request, 'brief_generator')) {
            return $denied;
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

        if ($denied = $this->chargeCredits($request, 'digest', [
            'project_id' => $project->id,
        ])) {
            return $denied;
        }

        ProjectEvent::log($request->user()->agency_id, $project->id, 'ai_bridge_called', [
            'endpoint' => 'digest',
            'credits'  => $this->credits->costFor('digest'),
        ]);

        return $this->success($result['data'] ?? null);
    }

    // POST /api/v1/ai/invoice-reminder — assistive draft, never auto-sends
    public function invoiceReminder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
        ]);

        $invoice = \App\Models\Invoice::with([
            'client:id,company_name,contact_user_id',
            'client.contactUser:id,name,email',
            'project:id,name',
            'agency:id,name',
        ])->findOrFail($validated['invoice_id']);

        $status = $invoice->displayStatus();
        if (!in_array($status, ['sent', 'overdue'], true)) {
            return $this->error('Reminders are only available for sent or overdue invoices.', [], 422);
        }

        $amount = number_format((float) $invoice->amount, 2);

        $result = $this->aiService->generateInvoiceReminder([
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'client_name'    => $invoice->client?->company_name
                ?? $invoice->client?->contactUser?->name
                ?? 'Client',
            'project_name'   => $invoice->project?->name ?? 'your project',
            'agency_name'    => $invoice->agency?->name ?? 'our team',
            'amount'         => (float) $invoice->amount,
            'amount_label'   => $amount,
            'due_date'       => $invoice->due_date?->toDateString(),
            'status'         => $status,
            'notes'          => $invoice->notes,
        ]);

        if (empty($result['success'])) {
            return $this->error($result['message'] ?? 'AI service unavailable', [], 503);
        }

        if ($denied = $this->chargeCredits($request, 'invoice_reminder', [
            'invoice_id' => $invoice->id,
        ])) {
            return $denied;
        }

        ProjectEvent::log($request->user()->agency_id, $invoice->project_id, 'ai_bridge_called', [
            'endpoint'   => 'invoice-reminder',
            'invoice_id' => $invoice->id,
            'credits'    => $this->credits->costFor('invoice_reminder'),
        ]);

        return $this->success($result['data'] ?? null);
    }
}
