<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AIBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(string $slug): Agency
    {
        return Agency::create([
            'name'         => "Agency {$slug}",
            'domain_slug'  => $slug,
            'brand_colors' => ['primary' => '#802AEE'],
        ]);
    }

    private function makeUser(Agency $agency, string $role = 'admin'): User
    {
        return User::create([
            'agency_id' => $agency->id,
            'role'      => $role,
            'name'      => ucfirst($role) . ' User',
            'email'     => "{$role}-{$agency->id}@bridge-test.com",
            'password'  => Hash::make('password'),
        ]);
    }

    private function makeClient(Agency $agency): Client
    {
        $contactUser = User::create([
            'agency_id' => $agency->id,
            'role'      => 'client',
            'name'      => 'Client Contact',
            'email'     => "client-contact-{$agency->id}@bridge-test.com",
            'password'  => Hash::make('password'),
        ]);

        return Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Bridge Test Co',
            'contact_user_id' => $contactUser->id,
        ]);
    }

    private function makeProject(Agency $agency, Client $client): Project
    {
        return Project::create([
            'agency_id'       => $agency->id,
            'client_id'       => $client->id,
            'name'            => 'Bridge Test Project',
            'type'            => 'web_development',
            'status'          => 'active',
            'estimated_hours' => 100,
            'start_date'      => '2026-07-01',
            'end_date'        => '2026-09-30',
        ]);
    }

    public function test_analyze_feedback_bridge_returns_structured_ticket(): void
    {
        Http::fake([
            'localhost:8001/analyze-feedback' => Http::response([
                'success' => true,
                'data'    => [
                    'title'           => 'Homepage premium upgrade',
                    'category'        => 'ui',
                    'priority'        => 'medium',
                    'subtasks'        => ['Typography audit', 'Spacing review'],
                    'assigned_role'   => 'designer',
                    'estimated_hours' => 6,
                ],
            ], 200),
        ]);

        $agency  = $this->makeAgency('bridge-11111');
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);

        $response = $this->actingAs($admin)->postJson('/api/v1/ai/analyze-feedback', [
            'feedback_text' => 'Make the homepage feel more premium and modern.',
            'project_type'  => $project->type,
            'project_id'    => $project->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Homepage premium upgrade')
            ->assertJsonPath('data.category', 'ui');

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'ai_bridge_called',
        ]);
    }

    public function test_ai_bridge_returns_503_when_fastapi_unreachable(): void
    {
        Http::fake([
            '*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
        ]);

        $agency  = $this->makeAgency('bridge-22222');
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);

        $response = $this->actingAs($admin)->postJson('/api/v1/ai/analyze-feedback', [
            'feedback_text' => 'Make the homepage feel more premium and modern.',
            'project_type'  => $project->type,
            'project_id'    => $project->id,
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('success', false);
    }

    public function test_get_health_score_saves_to_health_scores_table(): void
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

        $agency  = $this->makeAgency('bridge-33333');
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);

        $response = $this->actingAs($admin)->postJson('/api/v1/ai/health-score', [
            'project_id' => $project->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.score', 85)
            ->assertJsonPath('data.flag', 'green');

        $this->assertDatabaseHas('health_scores', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'score'      => 85,
            'flag'       => 'green',
        ]);
    }

    public function test_upsell_saves_suggestion_when_confidence_above_threshold(): void
    {
        Http::fake([
            'localhost:8001/upsell' => Http::response([
                'success' => true,
                'data'    => [
                    'upsell_ready'  => true,
                    'confidence'    => 0.85,
                    'tier'          => 'high',
                    'signals'       => ['project 90% complete'],
                    'model_version' => 'ensemble-v2-calibrated',
                    'service'       => 'upsell_recommended',
                    'reason'        => null,
                ],
            ], 200),
        ]);

        $agency  = $this->makeAgency('bridge-44444');
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);

        $response = $this->actingAs($admin)->postJson('/api/v1/ai/upsell', [
            'project_id' => $project->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.raw.upsell_ready', true)
            ->assertJsonPath('data.raw.tier', 'high');

        $this->assertDatabaseHas('upsell_suggestions', [
            'agency_id'    => $agency->id,
            'project_id'   => $project->id,
            'service_type' => 'upsell_recommended',
            'admin_status' => 'pending',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/upsell')
                && isset($body['completion_pct'], $body['health_score'], $body['revision_count'])
                && !isset($body['project_type'], $body['health_flag']);
        });
    }

    public function test_upsell_does_not_save_suggestion_when_confidence_below_threshold(): void
    {
        Http::fake([
            'localhost:8001/upsell' => Http::response([
                'success' => true,
                'data'    => [
                    'upsell_ready'  => false,
                    'confidence'    => 0.3,
                    'tier'          => 'low',
                    'service'       => null,
                    'reason'        => 'timing_not_right',
                ],
            ], 200),
        ]);

        $agency  = $this->makeAgency('bridge-55555');
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);

        $this->actingAs($admin)->postJson('/api/v1/ai/upsell', [
            'project_id' => $project->id,
        ]);

        $this->assertDatabaseMissing('upsell_suggestions', [
            'project_id' => $project->id,
        ]);
    }
}
