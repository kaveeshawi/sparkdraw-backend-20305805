<?php

namespace Tests\Feature;

use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AIServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_service_calls_fastapi_health_endpoint(): void
    {
        Http::fake([
            'localhost:8001/health' => Http::response([
                'success'  => true,
                'service'  => 'sparkdraw-ai',
                'version'  => '1.0',
                'provider' => 'openai',
            ], 200),
        ]);

        $serviceUrl = config('ai.service_url', 'http://localhost:8001');
        $response   = Http::get("{$serviceUrl}/health");

        $this->assertTrue($response->successful());
        $this->assertEquals('sparkdraw-ai', $response->json('service'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/health'));
    }

    public function test_ai_service_handles_fastapi_unreachable_gracefully(): void
    {
        Http::fake([
            '*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
        ]);

        $ai     = new AIService();
        $result = $ai->analyzeFeedback('The design feels too corporate and cold.', 'branding', 42);

        $this->assertFalse($result['success']);
        $this->assertEquals('AI service unavailable', $result['message']);
    }

    public function test_health_score_returns_structured_response(): void
    {
        Http::fake([
            'localhost:8001/health-score' => Http::response([
                'success' => true,
                'data'    => [
                    'score'       => 85,
                    'flag'        => 'green',
                    'reasons'     => [],
                    'computed_at' => now()->toIso8601String(),
                ],
            ], 200),
        ]);

        $ai     = new AIService();
        $result = $ai->getHealthScore([
            'project_id'              => 1,
            'revision_count'          => 1,
            'avg_revision_rate'       => 2.0,
            'hours_burn_ratio'        => 0.50,
            'approval_lag_avg_hours'  => 12.0,
            'deadline_slips'          => 0,
            'message_velocity_change' => 0.0,
            'sentiment_trend'         => 0.2,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(85, $result['data']['score']);
        $this->assertEquals('green', $result['data']['flag']);
    }
}
