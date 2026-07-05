<?php

namespace Tests\Feature;

use App\Jobs\AIFeedbackJob;
use App\Models\Agency;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Project;
use App\Models\Revision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RevisionTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

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
        static $counter = 0;
        $counter++;
        return User::create([
            'agency_id' => $agency->id,
            'role'      => $role,
            'name'      => ucfirst($role) . " User {$counter}",
            'email'     => "{$role}{$counter}@rev-test.com",
            'password'  => Hash::make('password'),
        ]);
    }

    private function makeClientWithUser(Agency $agency): array
    {
        $contactUser = $this->makeUser($agency, 'client');
        $client = Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Test Client Co',
            'contact_user_id' => $contactUser->id,
        ]);
        return [$client, $contactUser];
    }

    private function makeProject(Agency $agency, Client $client): Project
    {
        return Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client->id,
            'name'       => 'Test Project',
            'type'       => 'web_design',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);
    }

    private function makeRevision(Agency $agency, Project $project, Client $client, User $user, int $round = 1): Revision
    {
        return Revision::create([
            'agency_id'       => $agency->id,
            'project_id'      => $project->id,
            'client_id'       => $client->id,
            'submitted_by_id' => $user->id,
            'round_number'    => $round,
            'feedback_text'   => 'Please make the header larger and change the font.',
            'status'          => 'pending',
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_client_can_submit_feedback(): void
    {
        Queue::fake();

        $agency = $this->makeAgency('rev-11111');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        $response = $this->actingAs($clientUser)->postJson(
            "/api/v1/projects/{$project->id}/revisions",
            ['feedback_text' => 'Please make the hero image larger and bolder.']
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.round_number', 1)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('revisions', [
            'project_id'   => $project->id,
            'client_id'    => $client->id,
            'round_number' => 1,
            'status'       => 'pending',
        ]);
    }

    public function test_round_number_auto_increments(): void
    {
        Queue::fake();

        $agency = $this->makeAgency('rev-22222');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        // Create two existing revisions directly
        $this->makeRevision($agency, $project, $client, $clientUser, 1);
        $this->makeRevision($agency, $project, $client, $clientUser, 2);

        // Submit third — should auto-calculate round 3
        $response = $this->actingAs($clientUser)->postJson(
            "/api/v1/projects/{$project->id}/revisions",
            ['feedback_text' => 'Third round: adjust the color palette significantly.']
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.round_number', 3);

        $this->assertDatabaseHas('revisions', [
            'project_id'   => $project->id,
            'round_number' => 3,
        ]);
    }

    public function test_revision_dispatches_ai_feedback_job(): void
    {
        Queue::fake();

        $agency = $this->makeAgency('rev-33333');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        $this->actingAs($clientUser)->postJson(
            "/api/v1/projects/{$project->id}/revisions",
            ['feedback_text' => 'The navigation feels cluttered and hard to use.']
        );

        // Must be dispatched on 'ai' queue — never default
        Queue::assertPushedOn('ai', AIFeedbackJob::class);
    }

    public function test_pm_can_acknowledge_revision(): void
    {
        $agency = $this->makeAgency('rev-44444');
        $pm     = $this->makeUser($agency, 'pm');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project  = $this->makeProject($agency, $client);
        $revision = $this->makeRevision($agency, $project, $client, $clientUser, 1);

        $response = $this->actingAs($pm)->putJson(
            "/api/v1/projects/{$project->id}/revisions/{$revision->id}",
            ['status' => 'acknowledged']
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'acknowledged');

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'revision_acknowledged',
        ]);
    }

    public function test_accept_ticket_creates_tasks_from_ai_json(): void
    {
        $agency = $this->makeAgency('rev-55555');
        $pm     = $this->makeUser($agency, 'pm');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project  = $this->makeProject($agency, $client);
        $revision = $this->makeRevision($agency, $project, $client, $clientUser, 1);

        // Inject a mock AI ticket into the revision
        $revision->update([
            'ai_ticket_json' => [
                'title'    => 'UI premium upgrade',
                'priority' => 'medium',
                'subtasks' => ['Typography scale audit', 'Spacing review', 'Button refinement'],
            ],
        ]);

        $response = $this->actingAs($pm)->postJson(
            "/api/v1/projects/{$project->id}/revisions/{$revision->id}/accept-ticket"
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.tasks_count', 3);

        $this->assertCount(3, $response->json('data.created_task_ids'));

        // Each subtask becomes a real task in the DB
        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'title'      => 'Typography scale audit',
        ]);

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'ai_ticket_accepted',
        ]);
    }

    public function test_third_revision_logs_risk_event(): void
    {
        Queue::fake();

        $agency = $this->makeAgency('rev-66666');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        // Create 2 existing revisions
        $this->makeRevision($agency, $project, $client, $clientUser, 1);
        $this->makeRevision($agency, $project, $client, $clientUser, 2);

        // Third submission — should trigger revision_risk_detected
        $this->actingAs($clientUser)->postJson(
            "/api/v1/projects/{$project->id}/revisions",
            ['feedback_text' => 'This is the third time — please redo the layout completely.']
        );

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'revision_risk_detected',
        ]);
    }

    public function test_agency_b_cannot_submit_revision_to_agency_a_project(): void
    {
        Queue::fake();

        $agencyA = $this->makeAgency('rev-aaaaa');
        [$clientA, $clientUserA] = $this->makeClientWithUser($agencyA);
        $projectA = $this->makeProject($agencyA, $clientA);

        $agencyB = $this->makeAgency('rev-bbbbb');
        [$clientB, $clientUserB] = $this->makeClientWithUser($agencyB);

        // Client from Agency B tries to submit to Agency A's project
        $response = $this->actingAs($clientUserB)->postJson(
            "/api/v1/projects/{$projectA->id}/revisions",
            ['feedback_text' => 'Attempting cross-agency revision submission.']
        );

        // HasAgencyScope on Project causes 404 — prevents data leak
        $response->assertStatus(404);

        $this->assertDatabaseMissing('revisions', [
            'project_id' => $projectA->id,
            'client_id'  => $clientB->id,
        ]);
    }
}
