<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TimeLogTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static int $counter = 0;

    private function makeAgency(string $slug): Agency
    {
        return Agency::create([
            'name'         => "Agency {$slug}",
            'domain_slug'  => $slug,
            'brand_colors' => ['primary' => '#802AEE'],
        ]);
    }

    private function makeUser(Agency $agency, string $role = 'member'): User
    {
        self::$counter++;
        return User::create([
            'agency_id' => $agency->id,
            'role'      => $role,
            'name'      => ucfirst($role) . ' ' . self::$counter,
            'email'     => "{$role}" . self::$counter . '@timelog-test.com',
            'password'  => Hash::make('password'),
        ]);
    }

    private function makeProject(Agency $agency): Project
    {
        $contact = $this->makeUser($agency, 'client');
        $client  = Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Log Client',
            'contact_user_id' => $contact->id,
        ]);

        return Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client->id,
            'name'       => 'Log Project',
            'type'       => 'web_design',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);
    }

    private function makeTask(Agency $agency, Project $project): Task
    {
        return Task::create([
            'agency_id'       => $agency->id,
            'project_id'      => $project->id,
            'title'           => 'Loggable Task',
            'status'          => 'in_progress',
            'priority'        => 'medium',
            'estimated_hours' => 8,
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_time_log_requires_minimum_quarter_hour(): void
    {
        $agency  = $this->makeAgency('tlog-11111');
        $member  = $this->makeUser($agency, 'member');
        $project = $this->makeProject($agency);
        $task    = $this->makeTask($agency, $project);

        // 0.2 hours is below the 0.25 minimum
        $response = $this->actingAs($member)->postJson(
            "/api/v1/projects/{$project->id}/tasks/{$task->id}/time-logs",
            ['hours' => 0.2, 'logged_date' => '2026-07-10']
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('time_logs', ['task_id' => $task->id]);
    }

    public function test_time_log_exceeds_24_hours_rejected(): void
    {
        $agency  = $this->makeAgency('tlog-22222');
        $member  = $this->makeUser($agency, 'member');
        $project = $this->makeProject($agency);
        $task    = $this->makeTask($agency, $project);

        $response = $this->actingAs($member)->postJson(
            "/api/v1/projects/{$project->id}/tasks/{$task->id}/time-logs",
            ['hours' => 25, 'logged_date' => '2026-07-10']
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('time_logs', ['task_id' => $task->id]);
    }

    public function test_time_summary_returns_per_user_breakdown(): void
    {
        $agency  = $this->makeAgency('tlog-33333');
        $admin   = $this->makeUser($agency, 'admin');
        $member1 = $this->makeUser($agency, 'member');
        $member2 = $this->makeUser($agency, 'member');
        $project = $this->makeProject($agency);
        $task1   = $this->makeTask($agency, $project);
        $task2   = $this->makeTask($agency, $project);

        // Member 1 logs 3h on task1 in July 2026
        TimeLog::create([
            'agency_id'   => $agency->id,
            'task_id'     => $task1->id,
            'user_id'     => $member1->id,
            'hours'       => 3,
            'logged_date' => '2026-07-05',
        ]);

        // Member 1 logs 2h on task2 in July 2026
        TimeLog::create([
            'agency_id'   => $agency->id,
            'task_id'     => $task2->id,
            'user_id'     => $member1->id,
            'hours'       => 2,
            'logged_date' => '2026-07-06',
        ]);

        // Member 2 logs 5h on task1 in July 2026
        TimeLog::create([
            'agency_id'   => $agency->id,
            'task_id'     => $task1->id,
            'user_id'     => $member2->id,
            'hours'       => 5,
            'logged_date' => '2026-07-07',
        ]);

        // Member 2 logs 4h in August — should NOT appear in July summary
        TimeLog::create([
            'agency_id'   => $agency->id,
            'task_id'     => $task1->id,
            'user_id'     => $member2->id,
            'hours'       => 4,
            'logged_date' => '2026-08-01',
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/projects/{$project->id}/time-summary?month=2026-07");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.month', '2026-07');

        $breakdown = collect($response->json('data.breakdown'));

        // Two users should appear
        $this->assertCount(2, $breakdown);

        // Member 1 total: 3 + 2 = 5 hours
        $member1Summary = $breakdown->firstWhere('user_id', $member1->id);
        $this->assertNotNull($member1Summary);
        $this->assertEquals(5.0, $member1Summary['total_hours']);
        $this->assertCount(2, $member1Summary['tasks']); // worked on 2 tasks

        // Member 2 total: 5 hours (August hours excluded)
        $member2Summary = $breakdown->firstWhere('user_id', $member2->id);
        $this->assertNotNull($member2Summary);
        $this->assertEquals(5.0, $member2Summary['total_hours']);
    }
}
