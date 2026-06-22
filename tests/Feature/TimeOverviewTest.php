<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeLog;
use App\Models\User;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TimeOverviewTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
            'email'     => "{$role}" . self::$counter . '@time-overview-test.com',
            'password'  => Hash::make('password'),
        ]);
    }

    private function makeProject(Agency $agency): Project
    {
        $contact = $this->makeUser($agency, 'client');
        $client  = Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Overview Client',
            'contact_user_id' => $contact->id,
        ]);

        return Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client->id,
            'name'       => 'Overview Project',
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
            'title'           => 'Overview Task',
            'status'          => 'in_progress',
            'priority'        => 'medium',
            'estimated_hours' => 8,
        ]);
    }

    private function getOverview(User $user)
    {
        return $this->actingAs($user)->getJson('/api/v1/time-overview');
    }

    public function test_returns_billable_and_on_duty_hours_for_current_month(): void
    {
        $agency  = $this->makeAgency('to-agency-a');
        $user    = $this->makeUser($agency, 'member');
        $project = $this->makeProject($agency);
        $task    = $this->makeTask($agency, $project);

        TimeLog::create([
            'agency_id'   => $agency->id,
            'task_id'     => $task->id,
            'user_id'     => $user->id,
            'hours'       => 7.5,
            'logged_date' => '2026-08-05',
        ]);

        TimeLog::create([
            'agency_id'   => $agency->id,
            'task_id'     => $task->id,
            'user_id'     => $user->id,
            'hours'       => 5.0,
            'logged_date' => '2026-08-10',
        ]);

        // Outside current month — must not count toward billable
        TimeLog::create([
            'agency_id'   => $agency->id,
            'task_id'     => $task->id,
            'user_id'     => $user->id,
            'hours'       => 99.0,
            'logged_date' => '2026-07-31',
        ]);

        WorkSession::create([
            'user_id'      => $user->id,
            'agency_id'    => $agency->id,
            'clock_in_at'  => '2026-08-01 09:00:00',
            'clock_out_at' => '2026-08-01 17:00:00',
        ]);

        WorkSession::create([
            'user_id'      => $user->id,
            'agency_id'    => $agency->id,
            'clock_in_at'  => '2026-08-02 09:00:00',
            'clock_out_at' => '2026-08-02 17:00:00',
        ]);

        WorkSession::create([
            'user_id'      => $user->id,
            'agency_id'    => $agency->id,
            'clock_in_at'  => '2026-08-03 09:00:00',
            'clock_out_at' => '2026-08-03 17:00:00',
        ]);

        WorkSession::create([
            'user_id'      => $user->id,
            'agency_id'    => $agency->id,
            'clock_in_at'  => '2026-08-04 09:00:00',
            'clock_out_at' => '2026-08-04 17:00:00',
        ]);

        WorkSession::create([
            'user_id'      => $user->id,
            'agency_id'    => $agency->id,
            'clock_in_at'  => '2026-08-05 09:00:00',
            'clock_out_at' => '2026-08-05 17:00:00',
        ]);

        $response = $this->getOverview($user);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.period.start', '2026-08-01')
            ->assertJsonPath('data.period.end', '2026-08-31');

        $this->assertSame(12.5, (float) $response->json('data.billable_hours'));
        $this->assertSame(40.0, (float) $response->json('data.on_duty_hours'));
    }

    public function test_agency_isolation_excludes_other_agency_hours(): void
    {
        $agencyA = $this->makeAgency('to-isolate-a');
        $agencyB = $this->makeAgency('to-isolate-b');
        $userA   = $this->makeUser($agencyA, 'member');
        $userB   = $this->makeUser($agencyB, 'member');

        $projectA = $this->makeProject($agencyA);
        $taskA    = $this->makeTask($agencyA, $projectA);
        $projectB = $this->makeProject($agencyB);
        $taskB    = $this->makeTask($agencyB, $projectB);

        TimeLog::create([
            'agency_id'   => $agencyA->id,
            'task_id'     => $taskA->id,
            'user_id'     => $userA->id,
            'hours'       => 20.0,
            'logged_date' => '2026-08-08',
        ]);

        TimeLog::create([
            'agency_id'   => $agencyB->id,
            'task_id'     => $taskB->id,
            'user_id'     => $userB->id,
            'hours'       => 3.0,
            'logged_date' => '2026-08-08',
        ]);

        WorkSession::create([
            'user_id'      => $userA->id,
            'agency_id'    => $agencyA->id,
            'clock_in_at'  => '2026-08-08 09:00:00',
            'clock_out_at' => '2026-08-08 17:00:00',
        ]);

        WorkSession::create([
            'user_id'      => $userB->id,
            'agency_id'    => $agencyB->id,
            'clock_in_at'  => '2026-08-08 09:00:00',
            'clock_out_at' => '2026-08-08 13:00:00',
        ]);

        $responseB = $this->getOverview($userB);

        $responseB->assertOk();

        $this->assertSame(3.0, (float) $responseB->json('data.billable_hours'));
        $this->assertSame(4.0, (float) $responseB->json('data.on_duty_hours'));
    }

    public function test_open_work_session_counts_elapsed_time_until_now(): void
    {
        $agency = $this->makeAgency('to-open-session');
        $user   = $this->makeUser($agency, 'member');

        WorkSession::create([
            'user_id'      => $user->id,
            'agency_id'    => $agency->id,
            'clock_in_at'  => '2026-08-15 10:00:00',
            'clock_out_at' => null,
        ]);

        $response = $this->getOverview($user);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0.0, (float) $response->json('data.billable_hours'));
        $this->assertSame(2.0, (float) $response->json('data.on_duty_hours'));
    }

    public function test_client_cannot_access_time_overview(): void
    {
        $agency = $this->makeAgency('to-client-deny');
        $client = $this->makeUser($agency, 'client');

        $this->getOverview($client)->assertStatus(403);
    }
}
