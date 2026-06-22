<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TaskTest extends TestCase
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
            'email'     => "{$role}{$counter}@task-test.com",
            'password'  => Hash::make('password'),
        ]);
    }

    private function makeClient(Agency $agency): Client
    {
        $contact = $this->makeUser($agency, 'client');
        return Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Test Client Co',
            'contact_user_id' => $contact->id,
        ]);
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

    private function makeMilestone(Agency $agency, Project $project, string $title = 'M1'): Milestone
    {
        return Milestone::create([
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'title'      => $title,
            'due_date'   => '2026-08-01',
            'status'     => 'pending',
        ]);
    }

    private function makeTask(Agency $agency, Project $project, ?Milestone $milestone = null, string $status = 'todo'): Task
    {
        return Task::create([
            'agency_id'       => $agency->id,
            'project_id'      => $project->id,
            'milestone_id'    => $milestone?->id,
            'title'           => 'Test Task',
            'status'          => $status,
            'priority'        => 'medium',
            'estimated_hours' => 8,
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_pm_can_create_task_in_project(): void
    {
        $agency  = $this->makeAgency('task-11111');
        $pm      = $this->makeUser($agency, 'pm');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);

        $response = $this->actingAs($pm)->postJson("/api/v1/projects/{$project->id}/tasks", [
            'title'           => 'Design Landing Page',
            'priority'        => 'high',
            'estimated_hours' => 10,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Design Landing Page')
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.priority', 'high');

        $this->assertDatabaseHas('tasks', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'title'      => 'Design Landing Page',
        ]);

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'task_created',
        ]);
    }

    public function test_member_cannot_create_task(): void
    {
        $agency  = $this->makeAgency('task-22222');
        $member  = $this->makeUser($agency, 'member');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);

        $response = $this->actingAs($member)->postJson("/api/v1/projects/{$project->id}/tasks", [
            'title' => 'Unauthorized Task',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('tasks', ['title' => 'Unauthorized Task']);
    }

    public function test_tasks_grouped_by_status_for_kanban(): void
    {
        $agency  = $this->makeAgency('task-33333');
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);

        $this->makeTask($agency, $project, null, 'todo');
        $this->makeTask($agency, $project, null, 'in_progress');
        $this->makeTask($agency, $project, null, 'done');

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/projects/{$project->id}/tasks?group_by=status");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['todo', 'in_progress', 'in_review', 'done'],
            ]);

        // todo and in_progress have 1 each, in_review 0, done 1
        $this->assertCount(1, $response->json('data.todo'));
        $this->assertCount(1, $response->json('data.in_progress'));
        $this->assertCount(0, $response->json('data.in_review'));
        $this->assertCount(1, $response->json('data.done'));
    }

    public function test_status_update_logs_project_event(): void
    {
        $agency  = $this->makeAgency('task-44444');
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);
        $task    = $this->makeTask($agency, $project, null, 'todo');

        $response = $this->actingAs($admin)
            ->patchJson("/api/v1/projects/{$project->id}/tasks/{$task->id}/status", [
                'status' => 'in_progress',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'task_status_changed',
        ]);
    }

    public function test_completing_last_task_auto_completes_milestone(): void
    {
        $agency    = $this->makeAgency('task-55555');
        $admin     = $this->makeUser($agency, 'admin');
        $client    = $this->makeClient($agency);
        $project   = $this->makeProject($agency, $client);
        $milestone = $this->makeMilestone($agency, $project);

        // Create 2 tasks in the milestone — mark one done already
        $task1 = $this->makeTask($agency, $project, $milestone, 'done');
        $task2 = $this->makeTask($agency, $project, $milestone, 'todo');

        // Completing task2 should auto-complete the milestone
        $response = $this->actingAs($admin)
            ->patchJson("/api/v1/projects/{$project->id}/tasks/{$task2->id}/status", [
                'status' => 'done',
            ]);

        $response->assertStatus(200);

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

    public function test_user_can_only_log_own_time(): void
    {
        $agency  = $this->makeAgency('task-66666');
        $member  = $this->makeUser($agency, 'member');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);
        $task    = $this->makeTask($agency, $project);

        // Member logs time — user_id must be their own (not injectable via request)
        $response = $this->actingAs($member)->postJson(
            "/api/v1/projects/{$project->id}/tasks/{$task->id}/time-logs",
            [
                'hours'       => 2.5,
                'logged_date' => '2026-07-10',
                'notes'       => 'Worked on wireframes',
            ]
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('time_logs', [
            'task_id' => $task->id,
            'user_id' => $member->id,   // always the auth user
            'hours'   => 2.5,
        ]);
    }

    public function test_time_log_updates_actual_hours_on_task(): void
    {
        $agency  = $this->makeAgency('task-77777');
        $member  = $this->makeUser($agency, 'member');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);
        $task    = $this->makeTask($agency, $project);

        // Log 3 hours
        $this->actingAs($member)->postJson(
            "/api/v1/projects/{$project->id}/tasks/{$task->id}/time-logs",
            ['hours' => 3, 'logged_date' => '2026-07-10']
        );

        // Log 2 more hours
        $this->actingAs($member)->postJson(
            "/api/v1/projects/{$project->id}/tasks/{$task->id}/time-logs",
            ['hours' => 2, 'logged_date' => '2026-07-11']
        );

        // actual_hours should be ceil(5) = 5
        $this->assertDatabaseHas('tasks', [
            'id'           => $task->id,
            'actual_hours' => 5,
        ]);
    }

    public function test_hours_burn_ratio_calculated_correctly(): void
    {
        $agency  = $this->makeAgency('task-88888');
        $admin   = $this->makeUser($agency, 'admin');
        $client  = $this->makeClient($agency);
        $project = $this->makeProject($agency, $client);

        // Task with 10 estimated hours and 8 actual (80% burn ratio)
        $task = Task::create([
            'agency_id'       => $agency->id,
            'project_id'      => $project->id,
            'title'           => 'Burn Ratio Task',
            'status'          => 'in_progress',
            'priority'        => 'medium',
            'estimated_hours' => 10,
            'actual_hours'    => 8,
        ]);

        $this->assertEquals(0.8, $task->burn_ratio);

        // Task with no estimated hours — burn ratio is 0 (avoid division by zero)
        $task2 = Task::create([
            'agency_id'       => $agency->id,
            'project_id'      => $project->id,
            'title'           => 'No Estimate Task',
            'status'          => 'todo',
            'priority'        => 'low',
            'estimated_hours' => null,
            'actual_hours'    => 4,
        ]);

        $this->assertEquals(0.0, $task2->burn_ratio);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/projects/{$project->id}/tasks/{$task->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.task.burn_ratio', 0.8);
    }

    public function test_agency_b_cannot_access_agency_a_tasks(): void
    {
        $agencyA = $this->makeAgency('task-aaaaa');
        $clientA = $this->makeClient($agencyA);
        $projectA = $this->makeProject($agencyA, $clientA);
        $taskA    = $this->makeTask($agencyA, $projectA);

        $agencyB = $this->makeAgency('task-bbbbb');
        $adminB  = $this->makeUser($agencyB, 'admin');

        // Agency B admin tries to access Agency A's task via project A's URL
        $response = $this->actingAs($adminB)
            ->getJson("/api/v1/projects/{$projectA->id}/tasks/{$taskA->id}");

        // HasAgencyScope on Project causes 404 — correct (no data leak)
        $response->assertStatus(404);
    }
}
