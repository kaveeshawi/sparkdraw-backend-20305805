<?php

namespace Tests\Feature;

use App\Jobs\AIFeedbackJob;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\Revision;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AssistiveAITest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(string $slug = 'assist-agency'): Agency
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
            'email'     => "{$role}{$n}@assist-test.com",
            'password'  => Hash::make('password'),
        ]);
    }

    private function makeClientWithUser(Agency $agency): array
    {
        $contact = $this->makeUser($agency, 'client');

        $client = Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Assist Client',
            'contact_user_id' => $contact->id,
        ]);

        return [$client, $contact];
    }

    private function makeProject(Agency $agency, Client $client): Project
    {
        return Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client->id,
            'name'       => 'Assist Test Project',
            'type'       => 'web_design',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);
    }

    public function test_deadline_warning_fires_for_at_risk_tasks(): void
    {
        $agency  = $this->makeAgency('deadline-risk');
        $client  = $this->makeClientWithUser($agency)[0];
        $project = $this->makeProject($agency, $client);

        Task::create([
            'agency_id'       => $agency->id,
            'project_id'      => $project->id,
            'title'           => 'Homepage build',
            'status'          => 'in_progress',
            'estimated_hours' => 10,
            'actual_hours'    => 8,
            'deadline'        => now()->addDays(3)->toDateString(),
        ]);

        Artisan::call('deadlines:check');

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'deadline_at_risk',
        ]);

        $event = ProjectEvent::where('event_type', 'deadline_at_risk')->first();
        $this->assertEquals('Homepage build', $event->metadata['task_title']);
        $this->assertGreaterThan(0.70, $event->metadata['burn_ratio']);
    }

    public function test_deadline_warning_does_not_fire_for_completed_tasks(): void
    {
        $agency  = $this->makeAgency('deadline-done');
        $client  = $this->makeClientWithUser($agency)[0];
        $project = $this->makeProject($agency, $client);

        Task::create([
            'agency_id'       => $agency->id,
            'project_id'      => $project->id,
            'title'           => 'Completed task',
            'status'          => 'done',
            'estimated_hours' => 10,
            'actual_hours'    => 9,
            'deadline'        => now()->addDays(2)->toDateString(),
        ]);

        Artisan::call('deadlines:check');

        $this->assertDatabaseMissing('project_events', [
            'project_id' => $project->id,
            'event_type' => 'deadline_at_risk',
        ]);
    }

    public function test_revision_risk_fires_at_round_3(): void
    {
        Queue::fake();

        $agency = $this->makeAgency('rev-risk');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        Revision::create([
            'agency_id'     => $agency->id,
            'project_id'    => $project->id,
            'client_id'     => $client->id,
            'submitted_by_id' => $clientUser->id,
            'round_number'  => 1,
            'feedback_text' => 'First round feedback',
            'status'        => 'pending',
        ]);
        Revision::create([
            'agency_id'     => $agency->id,
            'project_id'    => $project->id,
            'client_id'     => $client->id,
            'submitted_by_id' => $clientUser->id,
            'round_number'  => 2,
            'feedback_text' => 'Second round feedback',
            'status'        => 'pending',
        ]);

        $this->actingAs($clientUser)->postJson(
            "/api/v1/projects/{$project->id}/revisions",
            ['feedback_text' => 'Third round — major layout changes needed throughout.']
        );

        $this->assertDatabaseHas('project_events', [
            'project_id' => $project->id,
            'event_type' => 'revision_risk_detected',
        ]);

        $event = ProjectEvent::where('event_type', 'revision_risk_detected')->first();
        $this->assertEquals(3, $event->metadata['round_number']);
        $this->assertStringContainsString('revision round 3', $event->metadata['message']);
    }

    public function test_weekly_digest_generates_and_saves(): void
    {
        Http::fake([
            'localhost:8001/digest' => Http::response([
                'success' => true,
                'data'    => [
                    'summary' => 'This week we completed the homepage design and are moving into development next.',
                ],
            ], 200),
        ]);

        $agency  = $this->makeAgency('digest-gen');
        $client  = $this->makeClientWithUser($agency)[0];
        $project = $this->makeProject($agency, $client);

        ProjectEvent::log($agency->id, $project->id, 'task_completed', [
            'task_title' => 'Wireframes',
        ]);

        Artisan::call('digests:generate');

        $this->assertDatabaseHas('revisions', [
            'project_id' => $project->id,
            'status'     => 'pending',
        ]);

        $revision = Revision::where('project_id', $project->id)->first();
        $this->assertStringContainsString('Weekly Digest', $revision->feedback_text);
        $this->assertArrayHasKey('summary', $revision->ai_ticket_json);

        $this->assertDatabaseHas('project_events', [
            'project_id' => $project->id,
            'event_type' => 'weekly_digest_generated',
        ]);
    }
}
