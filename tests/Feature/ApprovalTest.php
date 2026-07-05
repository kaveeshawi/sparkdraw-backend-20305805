<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Approval;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApprovalTest extends TestCase
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
            'email'     => "{$role}{$counter}@approval-test.com",
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

    private function makeDeliverable(Agency $agency, Project $project, User $uploader): Asset
    {
        return Asset::create([
            'agency_id'     => $agency->id,
            'project_id'    => $project->id,
            'uploader_id'   => $uploader->id,
            'file_path'     => 'uploads/test-deliverable.pdf',
            'original_name' => 'final-design.pdf',
            'version'       => 1,
            'is_deliverable' => true,
        ]);
    }

    private function makePendingApproval(Agency $agency, Project $project, Client $client, Asset $deliverable, User $pm): Approval
    {
        return Approval::create([
            'agency_id'       => $agency->id,
            'project_id'      => $project->id,
            'deliverable_id'  => $deliverable->id,
            'client_id'       => $client->id,
            'requested_by_id' => $pm->id,
            'status'          => 'pending',
            'requested_at'    => now()->subHours(2), // 2 hours ago
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_pm_can_request_approval(): void
    {
        $agency = $this->makeAgency('apv-11111');
        $pm     = $this->makeUser($agency, 'pm');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project     = $this->makeProject($agency, $client);
        $deliverable = $this->makeDeliverable($agency, $project, $pm);

        $response = $this->actingAs($pm)->postJson(
            "/api/v1/projects/{$project->id}/approvals",
            ['deliverable_id' => $deliverable->id]
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('approvals', [
            'project_id'      => $project->id,
            'deliverable_id'  => $deliverable->id,
            'client_id'       => $client->id,
            'requested_by_id' => $pm->id,
            'status'          => 'pending',
        ]);

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'approval_requested',
        ]);
    }

    public function test_client_can_approve_deliverable(): void
    {
        $agency = $this->makeAgency('apv-22222');
        $pm     = $this->makeUser($agency, 'pm');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project     = $this->makeProject($agency, $client);
        $deliverable = $this->makeDeliverable($agency, $project, $pm);
        $approval    = $this->makePendingApproval($agency, $project, $client, $deliverable, $pm);

        $response = $this->actingAs($clientUser)->patchJson(
            "/api/v1/projects/{$project->id}/approvals/{$approval->id}/approve"
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('approvals', [
            'id'     => $approval->id,
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'approval_given',
        ]);
    }

    public function test_client_can_reject_with_reason(): void
    {
        $agency = $this->makeAgency('apv-33333');
        $pm     = $this->makeUser($agency, 'pm');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project     = $this->makeProject($agency, $client);
        $deliverable = $this->makeDeliverable($agency, $project, $pm);
        $approval    = $this->makePendingApproval($agency, $project, $client, $deliverable, $pm);

        $response = $this->actingAs($clientUser)->patchJson(
            "/api/v1/projects/{$project->id}/approvals/{$approval->id}/reject",
            ['reason' => 'The colors do not match our brand guidelines.']
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('approvals', [
            'id'     => $approval->id,
            'status' => 'rejected',
        ]);

        $this->assertDatabaseHas('project_events', [
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'event_type' => 'approval_rejected',
        ]);
    }

    public function test_approval_lag_calculated_correctly(): void
    {
        $agency = $this->makeAgency('apv-44444');
        $pm     = $this->makeUser($agency, 'pm');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project     = $this->makeProject($agency, $client);
        $deliverable = $this->makeDeliverable($agency, $project, $pm);

        // Set requested_at 5 hours ago so we can verify lag calculation
        $approval = Approval::create([
            'agency_id'       => $agency->id,
            'project_id'      => $project->id,
            'deliverable_id'  => $deliverable->id,
            'client_id'       => $client->id,
            'requested_by_id' => $pm->id,
            'status'          => 'pending',
            'requested_at'    => now()->subHours(5),
        ]);

        $this->actingAs($clientUser)->patchJson(
            "/api/v1/projects/{$project->id}/approvals/{$approval->id}/approve"
        );

        $approval->refresh();

        // Lag should be 5 hours (ceil of diff)
        $this->assertNotNull($approval->approval_lag_hours);
        $this->assertGreaterThanOrEqual(5, $approval->approval_lag_hours);
        $this->assertNotNull($approval->responded_at);
    }

    public function test_approval_lag_stored_in_project_event(): void
    {
        $agency = $this->makeAgency('apv-55555');
        $pm     = $this->makeUser($agency, 'pm');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project     = $this->makeProject($agency, $client);
        $deliverable = $this->makeDeliverable($agency, $project, $pm);
        $approval    = $this->makePendingApproval($agency, $project, $client, $deliverable, $pm);

        $this->actingAs($clientUser)->patchJson(
            "/api/v1/projects/{$project->id}/approvals/{$approval->id}/approve"
        );

        // The project_event for approval_given must store approval_lag_hours in metadata
        $event = \App\Models\ProjectEvent::where('project_id', $project->id)
            ->where('event_type', 'approval_given')
            ->first();

        $this->assertNotNull($event);
        $this->assertArrayHasKey('approval_lag_hours', $event->metadata);
        $this->assertIsInt($event->metadata['approval_lag_hours']);
    }

    public function test_member_cannot_request_approval(): void
    {
        $agency = $this->makeAgency('apv-66666');
        $member = $this->makeUser($agency, 'member');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project     = $this->makeProject($agency, $client);
        $deliverable = $this->makeDeliverable($agency, $project, $member);

        $response = $this->actingAs($member)->postJson(
            "/api/v1/projects/{$project->id}/approvals",
            ['deliverable_id' => $deliverable->id]
        );

        $response->assertStatus(403);

        $this->assertDatabaseMissing('approvals', [
            'project_id' => $project->id,
        ]);
    }
}
