<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MessageUnreadTest extends TestCase
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
        static $counter = 0;
        $counter++;

        return User::create([
            'agency_id' => $agency->id,
            'role'      => $role,
            'name'      => ucfirst($role)." User {$counter}",
            'email'     => "{$role}{$counter}@msg-unread.test",
            'password'  => Hash::make('password'),
        ]);
    }

    private function makeClientProject(Agency $agency): array
    {
        $contact = $this->makeUser($agency, 'client');
        $client = Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Unread Co',
            'contact_user_id' => $contact->id,
        ]);
        $project = Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client->id,
            'name'       => 'Unread Project',
            'type'       => 'web_design',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);

        return [$client, $contact, $project];
    }

    public function test_unread_summary_counts_client_messages_for_admin(): void
    {
        $agency = $this->makeAgency('unread-a');
        $admin = $this->makeUser($agency, 'admin');
        [, $clientUser, $project] = $this->makeClientProject($agency);

        Message::create([
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'sender_id'  => $clientUser->id,
            'body'       => 'Hello from client',
        ]);
        Message::create([
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'sender_id'  => $clientUser->id,
            'body'       => 'Second ping',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/messages/unread');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.clients.0.unread', 2);
    }

    public function test_mark_read_clears_unread_for_overall_thread(): void
    {
        $agency = $this->makeAgency('unread-b');
        $admin = $this->makeUser($agency, 'admin');
        [, $clientUser, $project] = $this->makeClientProject($agency);

        Message::create([
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'sender_id'  => $clientUser->id,
            'body'       => 'Please review',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/projects/{$project->id}/messages/read")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($admin)
            ->getJson('/api/v1/messages/unread')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_direct_thread_unread_is_peer_scoped(): void
    {
        $agency = $this->makeAgency('unread-c');
        $admin = $this->makeUser($agency, 'admin');
        [, $clientUser, $project] = $this->makeClientProject($agency);

        Message::create([
            'agency_id'    => $agency->id,
            'project_id'   => $project->id,
            'sender_id'    => $clientUser->id,
            'recipient_id' => $admin->id,
            'body'         => 'Private note',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/messages/unread')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.threads.0.peer_user_id', $clientUser->id);

        $this->actingAs($admin)
            ->postJson("/api/v1/projects/{$project->id}/messages/read", [
                'with' => $clientUser->id,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->getJson('/api/v1/messages/unread')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_own_messages_do_not_count_as_unread(): void
    {
        $agency = $this->makeAgency('unread-d');
        $admin = $this->makeUser($agency, 'admin');
        [, , $project] = $this->makeClientProject($agency);

        Message::create([
            'agency_id'  => $agency->id,
            'project_id' => $project->id,
            'sender_id'  => $admin->id,
            'body'       => 'Sent by me',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/messages/unread')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }
}
