<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\HealthScore;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\User;
use App\Services\HealthScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthScoreTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(string $slug = 'hs-agency'): Agency
    {
        return Agency::create([
            'name'         => "Agency {$slug}",
            'domain_slug'  => $slug,
            'brand_colors' => ['primary' => '#802AEE'],
        ]);
    }

    private function makeUser(Agency $agency, string $role = 'admin'): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'agency_id' => $agency->id,
            'role'      => $role,
            'name'      => ucfirst($role) . " {$n}",
            'email'     => "{$role}{$n}@hs-test.com",
            'password'  => Hash::make('password'),
        ]);
    }

    private function makeClient(Agency $agency): Client
    {
        $contact = $this->makeUser($agency, 'client');

        return Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Test Client',
            'contact_user_id' => $contact->id,
        ]);
    }

    private function makeProject(Agency $agency, Client $client): Project
    {
        return Project::create([
            'agency_id'       => $agency->id,
            'client_id'       => $client->id,
            'name'            => 'Health Test Project',
            'type'            => 'web_design',
            'status'          => 'active',
            'estimated_hours' => 100,
            'start_date'      => '2026-07-01',
            'end_date'        => '2026-09-30',
        ]);
    }

    private function fakeHealthScore(int $score, string $flag, array $reasons = []): void
    {
        Http::fake([
            'localhost:8001/health-score' => Http::response([
                'success' => true,
                'data'    => [
                    'score'       => $score,
                    'flag'        => $flag,
                    'reasons'     => $reasons,
                    'computed_at' => now()->toIso8601String(),
                ],
            ], 200),
        ]);
    }

    public function test_health_score_computed_and_saved(): void
    {
        $agency  = $this->makeAgency();
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);

        $this->fakeHealthScore(85, 'green', ['On track']);

        $this->actingAs($admin);

        $service = app(HealthScoreService::class);
        $result  = $service->computeAndSave($project);

        $this->assertNotNull($result);
        $this->assertDatabaseHas('health_scores', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'score'      => 85,
            'flag'       => 'green',
        ]);
    }

    public function test_agency_average_returns_correct_counts(): void
    {
        $agency  = $this->makeAgency('hs-avg');
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);

        HealthScore::create([
            'agency_id'   => $agency->id,
            'project_id'  => $project->id,
            'score'       => 80,
            'flag'        => 'green',
            'reasons'     => [],
            'computed_at' => now(),
        ]);

        $client2  = Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Client Two',
            'contact_user_id' => $this->makeUser($agency, 'client')->id,
        ]);
        $project2 = Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client2->id,
            'name'       => 'Amber Project',
            'type'       => 'branding',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);

        HealthScore::create([
            'agency_id'   => $agency->id,
            'project_id'  => $project2->id,
            'score'       => 55,
            'flag'        => 'amber',
            'reasons'     => ['Hours burn at 90%'],
            'computed_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/health-scores/agency-average');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.green_count', 1)
            ->assertJsonPath('data.amber_count', 1)
            ->assertJsonPath('data.red_count', 0);
    }

    public function test_red_flag_logs_critical_event(): void
    {
        $agency  = $this->makeAgency('hs-red');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);

        HealthScore::create([
            'agency_id'   => $agency->id,
            'project_id'  => $project->id,
            'score'       => 55,
            'flag'        => 'amber',
            'reasons'     => ['At risk'],
            'computed_at' => now()->subHour(),
        ]);

        $this->fakeHealthScore(25, 'red', ['Revision rate above average']);

        $service = app(HealthScoreService::class);
        $service->computeAndSave($project);

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'health_score_critical',
        ]);

        $event = ProjectEvent::where('event_type', 'health_score_critical')->first();
        $this->assertEquals('amber', $event->metadata['previous_flag']);
        $this->assertEquals('red', $event->metadata['flag']);
    }

    public function test_manual_compute_endpoint_works(): void
    {
        $agency  = $this->makeAgency('hs-manual');
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);

        $this->fakeHealthScore(72, 'green', []);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/health-scores/compute/{$project->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.score', 72)
            ->assertJsonPath('data.flag', 'green');

        $this->assertDatabaseHas('health_scores', [
            'project_id' => $project->id,
            'score'      => 72,
        ]);
    }
}
