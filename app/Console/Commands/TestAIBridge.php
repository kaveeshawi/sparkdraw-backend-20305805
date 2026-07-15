<?php

namespace App\Console\Commands;

use App\Services\AIService;
use Illuminate\Console\Command;

class TestAIBridge extends Command
{
    protected $signature   = 'ai:test-bridge';
    protected $description = 'Smoke-test every Laravel ↔ FastAPI endpoint and print PASS/FAIL';

    public function handle(AIService $ai): int
    {
        $this->info('Sparkdraw AI Bridge — endpoint smoke test');
        $this->line(str_repeat('─', 50));

        $results = [
            'GET /health'         => $this->testHealth($ai),
            'POST /analyze-feedback' => $this->testFeedback($ai),
            'POST /sentiment'        => $this->testSentiment($ai),
            'POST /health-score'     => $this->testHealthScore($ai),
            'POST /upsell'           => $this->testUpsell($ai),
            'POST /estimate-hours'   => $this->testEstimateHours($ai),
            'POST /brief-generator'  => $this->testBriefGenerator($ai),
            'POST /digest'           => $this->testDigest($ai),
        ];

        $allPassed = true;
        foreach ($results as $label => $passed) {
            $status = $passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>';
            $this->line("  {$status}  {$label}");
            if (!$passed) {
                $allPassed = false;
            }
        }

        $this->line(str_repeat('─', 50));

        if ($allPassed) {
            $this->info('All endpoints responding correctly.');
            return self::SUCCESS;
        }

        $this->error('One or more endpoints failed — check FastAPI is running on ' . config('ai.service_url'));
        return self::FAILURE;
    }

    private function testHealth(AIService $ai): bool
    {
        try {
            $serviceUrl = rtrim(config('ai.service_url', 'http://localhost:8001'), '/');
            $response   = \Illuminate\Support\Facades\Http::timeout(5)->get("{$serviceUrl}/health");
            return $response->successful() && $response->json('success') === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function testFeedback(AIService $ai): bool
    {
        $result = $ai->analyzeFeedback(
            'Please make the hero section more premium and modern.',
            'web_design',
            1
        );
        return !empty($result['success']);
    }

    private function testSentiment(AIService $ai): bool
    {
        $result = $ai->getSentiment('The project looks great so far!', 1, 1);
        return !empty($result['success']);
    }

    private function testHealthScore(AIService $ai): bool
    {
        $result = $ai->getHealthScore([
            'project_id'              => 1,
            'revision_count'          => 2,
            'avg_revision_rate'       => 2.5,
            'hours_burn_ratio'        => 0.60,
            'approval_lag_avg_hours'  => 24.0,
            'deadline_slips'          => 0,
            'message_velocity_change' => 0.0,
            'sentiment_trend'         => 0.1,
        ]);
        return !empty($result['success']) && isset($result['data']['score']);
    }

    private function testUpsell(AIService $ai): bool
    {
        $result = $ai->getUpsellSuggestion([
            'completion_pct'           => 0.90,
            'health_score'             => 85.0,
            'revision_count'           => 2,
            'approval_lag_hrs'         => 24.0,
            'budget_used_pct'          => 0.65,
            'sentiment_avg'            => 0.5,
            'project_age_days'         => 45,
            'invoice_count'            => 1,
            'days_since_last_revision' => 7.0,
        ]);

        return !empty($result['success']) && !empty($result['data']['upsell_ready']);
    }

    private function testEstimateHours(AIService $ai): bool
    {
        $result = $ai->estimateHours('Design landing page hero', 'Create visual mockup', 'web_design');
        return !empty($result['success']);
    }

    private function testBriefGenerator(AIService $ai): bool
    {
        $result = $ai->generateBrief('Acme Website', 'web_design', 5000.00, 12);
        return !empty($result['success']);
    }

    private function testDigest(AIService $ai): bool
    {
        $result = $ai->generateDigest('Acme Website', 'Acme Corp', 'Completed 3 tasks this week.');
        return !empty($result['success']);
    }
}
