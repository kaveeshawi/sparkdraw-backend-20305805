<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeAgency(string $slug = 'agency-aaaaa'): Agency
    {
        return Agency::create([
            'name'         => 'Agency ' . $slug,
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
            'email'     => "{$role}-{$agency->id}@test.com",
            'password'  => Hash::make('password'),
        ]);
    }

    private function makeClient(Agency $agency): Client
    {
        $contactUser = User::create([
            'agency_id' => $agency->id,
            'role'      => 'client',
            'name'      => 'Client Contact',
            'email'     => "client-contact-{$agency->id}@test.com",
            'password'  => Hash::make('password'),
        ]);

        return Client::create([
            'agency_id'      => $agency->id,
            'company_name'   => 'Acme Corp',
            'contact_user_id' => $contactUser->id,
        ]);
    }

    private function projectPayload(Client $client): array
    {
        return [
            'name'       => 'Website Redesign',
            'client_id'  => $client->id,
            'type'       => 'web_design',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
            'budget'     => 5000,
        ];
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_admin_can_create_project(): void
    {
        $agency = $this->makeAgency('agency-11111');
        $admin  = $this->makeUser($agency, 'admin');
        $client = $this->makeClient($agency);

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/projects', $this->projectPayload($client));

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Website Redesign')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('projects', [
            'agency_id' => $agency->id,
            'name'      => 'Website Redesign',
        ]);

        // Auto-kickoff milestone must be created
        $this->assertDatabaseHas('milestones', [
            'agency_id' => $agency->id,
            'title'     => 'Project Kickoff',
        ]);
    }

    public function test_member_cannot_create_project(): void
    {
        $agency  = $this->makeAgency('agency-22222');
        $member  = $this->makeUser($agency, 'member');
        $client  = $this->makeClient($agency);

        $response = $this->actingAs($member)
            ->postJson('/api/v1/projects', $this->projectPayload($client));

        $response->assertStatus(403);
        $this->assertDatabaseMissing('projects', ['name' => 'Website Redesign', 'agency_id' => $agency->id]);
    }

    public function test_project_index_returns_only_agency_projects(): void
    {
        $agencyA = $this->makeAgency('agency-aaaaa');
        $adminA  = $this->makeUser($agencyA, 'admin');
        $clientA = $this->makeClient($agencyA);

        $agencyB = $this->makeAgency('agency-bbbbb');
        $adminB  = $this->makeUser($agencyB, 'admin');
        $clientB = $this->makeClient($agencyB);

        // Create one project per agency
        Project::create([
            'agency_id'  => $agencyA->id,
            'client_id'  => $clientA->id,
            'name'       => 'Agency A Project',
            'type'       => 'web_design',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);

        Project::create([
            'agency_id'  => $agencyB->id,
            'client_id'  => $clientB->id,
            'name'       => 'Agency B Project',
            'type'       => 'web_design',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);

        $response = $this->actingAs($adminA)->getJson('/api/v1/projects');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $projectNames = collect($response->json('data.projects'))->pluck('name');
        $this->assertTrue($projectNames->contains('Agency A Project'));
        $this->assertFalse($projectNames->contains('Agency B Project'));
    }

    public function test_project_show_includes_milestones_and_tasks(): void
    {
        $agency  = $this->makeAgency('agency-33333');
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);

        $project = Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client->id,
            'name'       => 'Full Project',
            'type'       => 'branding',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);

        Milestone::create([
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'title'      => 'Phase 1',
            'due_date'   => '2026-07-31',
            'status'     => 'pending',
        ]);

        $response = $this->actingAs($admin)->getJson("/api/v1/projects/{$project->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'status', 'milestones', 'task_counts', 'progress_pct',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.milestones'));
    }

    public function test_project_soft_delete_not_hard_delete(): void
    {
        $agency  = $this->makeAgency('agency-44444');
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);

        $project = Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client->id,
            'name'       => 'Deletable Project',
            'type'       => 'web_design',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);

        $response = $this->actingAs($admin)->deleteJson("/api/v1/projects/{$project->id}");
        $response->assertStatus(200);

        // Row still exists in DB (soft delete sets deleted_at)
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Deletable Project']);
    }

    public function test_agency_b_cannot_access_agency_a_project(): void
    {
        $agencyA = $this->makeAgency('agency-ccccc');
        $clientA = $this->makeClient($agencyA);

        $agencyB = $this->makeAgency('agency-ddddd');
        $adminB  = $this->makeUser($agencyB, 'admin');

        $projectA = Project::create([
            'agency_id'  => $agencyA->id,
            'client_id'  => $clientA->id,
            'name'       => 'Agency A Secret Project',
            'type'       => 'web_design',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);

        // Admin from Agency B tries to fetch Agency A's project by ID
        $response = $this->actingAs($adminB)->getJson("/api/v1/projects/{$projectA->id}");

        // HasAgencyScope causes 404 — correct behavior (no 403 leak that resource exists)
        $response->assertStatus(404);
    }

    public function test_milestone_created_logs_project_event(): void
    {
        $agency  = $this->makeAgency('agency-55555');
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);

        $project = Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client->id,
            'name'       => 'Event Log Project',
            'type'       => 'web_design',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);

        $response = $this->actingAs($admin)->postJson("/api/v1/projects/{$project->id}/milestones", [
            'title'    => 'Discovery Phase',
            'due_date' => '2026-07-15',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Discovery Phase');

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'milestone_created',
        ]);
    }

    public function test_completing_milestone_updates_status(): void
    {
        $agency    = $this->makeAgency('agency-66666');
        $admin     = $this->makeUser($agency, 'admin');
        $client    = $this->makeClient($agency);

        $project = Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client->id,
            'name'       => 'Completion Test Project',
            'type'       => 'web_design',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);

        $milestone = Milestone::create([
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'title'      => 'Milestone To Complete',
            'due_date'   => '2026-07-20',
            'status'     => 'pending',
        ]);

        $response = $this->actingAs($admin)
            ->patchJson("/api/v1/projects/{$project->id}/milestones/{$milestone->id}/complete");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('milestones', [
            'id'     => $milestone->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'milestone_completed',
        ]);
    }
}
