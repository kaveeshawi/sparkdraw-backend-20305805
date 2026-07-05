<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    private function seedAgency(): array
    {
        $agency = Agency::create([
            'name'         => 'Test Agency',
            'domain_slug'  => 'test-clients',
            'brand_colors' => ['primary' => '#802AEE'],
        ]);

        $admin = User::create([
            'agency_id' => $agency->id,
            'role'      => 'admin',
            'name'      => 'Agency Admin',
            'email'     => 'admin-clients@test.com',
            'password'  => Hash::make('password'),
        ]);

        $pm = User::create([
            'agency_id' => $agency->id,
            'role'      => 'pm',
            'name'      => 'Project Manager',
            'email'     => 'pm-clients@test.com',
            'password'  => Hash::make('password'),
        ]);

        $member = User::create([
            'agency_id' => $agency->id,
            'role'      => 'member',
            'name'      => 'Team Member',
            'email'     => 'member-clients@test.com',
            'password'  => Hash::make('password'),
        ]);

        $contact = User::create([
            'agency_id' => $agency->id,
            'role'      => 'client',
            'name'      => 'Acme Contact',
            'email'     => 'contact@acme.test',
            'password'  => Hash::make('password'),
        ]);

        $client = Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Acme Corp',
            'contact_user_id' => $contact->id,
        ]);

        return compact('agency', 'admin', 'pm', 'member', 'client', 'contact');
    }

    public function test_admin_can_upload_client_avatar(): void
    {
        ['admin' => $admin, 'client' => $client, 'contact' => $contact] = $this->seedAgency();

        $response = $this->actingAs($admin)->postJson("/api/v1/clients/{$client->id}/avatar", [
            'avatar' => \Illuminate\Http\UploadedFile::fake()->image('client.jpg', 200, 200),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['avatar_url', 'avatar_path', 'id']]);

        $this->assertNotNull($response->json('data.avatar_url'));
        $contact->refresh();
        $this->assertNotNull($contact->avatar_path);
    }

    public function test_index_returns_rich_payload_for_admin(): void
    {
        ['admin' => $admin, 'client' => $client] = $this->seedAgency();

        $response = $this->actingAs($admin)->getJson('/api/v1/clients');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $row = collect($response->json('data'))->firstWhere('id', $client->id);

        $this->assertNotNull($row);
        $this->assertSame('Acme Corp', $row['company_name']);
        $this->assertSame('Acme Contact', $row['contact_name']);
        $this->assertSame('contact@acme.test', $row['contact_email']);
        $this->assertArrayHasKey('projects_count', $row);
        $this->assertArrayHasKey('at_risk', $row);
        $this->assertArrayHasKey('domain_slug', $row);
        $this->assertArrayHasKey('invite_status', $row);
        $this->assertArrayHasKey('last_seen_at', $row);
        $this->assertArrayHasKey('tier', $row);
        $this->assertArrayHasKey('avatar_url', $row);
        $this->assertArrayHasKey('avatar_path', $row);
        $this->assertSame('test-clients', $row['domain_slug']);
        $this->assertTrue($row['can_manage']);
        $this->assertTrue($row['can_invite']);
    }

    public function test_member_index_hides_contact_details(): void
    {
        ['member' => $member, 'client' => $client] = $this->seedAgency();

        $response = $this->actingAs($member)->getJson('/api/v1/clients');

        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $client->id);

        $this->assertNotNull($row);
        $this->assertSame('Acme Corp', $row['company_name']);
        $this->assertArrayNotHasKey('contact_email', $row);
        $this->assertArrayNotHasKey('contact_name', $row);
        $this->assertFalse($row['can_manage']);
        $this->assertFalse($row['can_invite']);
        $this->assertTrue($row['can_chat']);
    }

    public function test_admin_can_create_client(): void
    {
        ['admin' => $admin] = $this->seedAgency();

        $response = $this->actingAs($admin)->postJson('/api/v1/clients', [
            'company_name'  => 'NovaTech',
            'contact_name'  => 'Jane Client',
            'contact_email' => 'jane@novatech.test',
            'send_email'    => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.company_name', 'NovaTech')
            ->assertJsonPath('data.contact_email', 'jane@novatech.test')
            ->assertJsonPath('data.tier', null);

        $this->assertDatabaseHas('clients', ['company_name' => 'NovaTech', 'tier' => null]);
        $this->assertDatabaseHas('users', [
            'email' => 'jane@novatech.test',
            'role'  => 'client',
        ]);
    }

    public function test_admin_can_create_client_with_tier(): void
    {
        ['admin' => $admin] = $this->seedAgency();

        $response = $this->actingAs($admin)->postJson('/api/v1/clients', [
            'company_name'  => 'VIP Labs',
            'contact_name'  => 'VIP Contact',
            'contact_email' => 'vip@labs.test',
            'tier'          => 'VIP',
            'send_email'    => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.tier', 'vip');

        $this->assertDatabaseHas('clients', [
            'company_name' => 'VIP Labs',
            'tier'         => 'vip',
        ]);
    }

    public function test_admin_can_update_and_clear_client_tier(): void
    {
        ['admin' => $admin, 'client' => $client] = $this->seedAgency();

        $client->update(['tier' => 'enterprise']);

        $this->actingAs($admin)->putJson("/api/v1/clients/{$client->id}", [
            'tier' => 'vip',
        ])->assertOk()
            ->assertJsonPath('data.tier', 'vip');

        $this->assertDatabaseHas('clients', [
            'id'   => $client->id,
            'tier' => 'vip',
        ]);

        $this->actingAs($admin)->putJson("/api/v1/clients/{$client->id}", [
            'tier' => '',
        ])->assertOk()
            ->assertJsonPath('data.tier', null);

        $this->assertDatabaseHas('clients', [
            'id'   => $client->id,
            'tier' => null,
        ]);
    }

    public function test_index_can_filter_by_tier(): void
    {
        ['admin' => $admin, 'client' => $client, 'agency' => $agency] = $this->seedAgency();

        $client->update(['tier' => 'vip']);

        $otherContact = User::create([
            'agency_id' => $agency->id,
            'role'      => 'client',
            'name'      => 'Enterprise Contact',
            'email'     => 'ent@acme.test',
            'password'  => Hash::make('password'),
        ]);

        Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Enterprise Co',
            'tier'            => 'enterprise',
            'contact_user_id' => $otherContact->id,
        ]);

        $vipOnly = $this->actingAs($admin)->getJson('/api/v1/clients?tier=vip');
        $vipOnly->assertOk();
        $ids = collect($vipOnly->json('data'))->pluck('id')->all();
        $this->assertContains($client->id, $ids);
        $this->assertCount(1, $ids);

        $invalid = $this->actingAs($admin)->getJson('/api/v1/clients?tier=unknown');
        $invalid->assertOk();
        $this->assertGreaterThanOrEqual(2, count($invalid->json('data')));
    }

    public function test_store_rejects_invalid_tier(): void
    {
        ['admin' => $admin] = $this->seedAgency();

        $this->actingAs($admin)->postJson('/api/v1/clients', [
            'company_name'  => 'Bad Tier Co',
            'contact_name'  => 'Bad Contact',
            'contact_email' => 'bad-tier@test.com',
            'tier'          => 'gold',
            'send_email'    => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['tier']);
    }

    public function test_pm_can_update_but_not_create_or_delete(): void
    {
        ['pm' => $pm, 'client' => $client] = $this->seedAgency();

        $this->actingAs($pm)->putJson("/api/v1/clients/{$client->id}", [
            'company_name' => 'Acme International',
        ])->assertOk()
            ->assertJsonPath('data.company_name', 'Acme International');

        $this->actingAs($pm)->postJson('/api/v1/clients', [
            'company_name'  => 'Blocked Co',
            'contact_name'  => 'Blocked',
            'contact_email' => 'blocked@test.com',
        ])->assertForbidden();

        $this->actingAs($pm)->deleteJson("/api/v1/clients/{$client->id}")
            ->assertForbidden();
    }

    public function test_admin_can_delete_client_without_active_projects(): void
    {
        ['admin' => $admin, 'client' => $client] = $this->seedAgency();

        $this->actingAs($admin)->deleteJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
    }

    public function test_admin_cannot_delete_client_with_active_projects(): void
    {
        ['admin' => $admin, 'client' => $client, 'agency' => $agency] = $this->seedAgency();

        Project::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'name'      => 'Active Project',
            'type'      => 'web',
            'status'    => 'active',
            'budget'    => 1000,
        ]);

        $this->actingAs($admin)->deleteJson("/api/v1/clients/{$client->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_tenant_isolation_on_client_show(): void
    {
        ['admin' => $admin, 'client' => $client] = $this->seedAgency();

        $otherAgency = Agency::create([
            'name'        => 'Other Agency',
            'domain_slug' => 'other-agency',
        ]);

        $otherContact = User::create([
            'agency_id' => $otherAgency->id,
            'role'      => 'client',
            'name'      => 'Other Contact',
            'email'     => 'other@client.test',
            'password'  => Hash::make('password'),
        ]);

        $otherClient = Client::withoutAgencyScope()->create([
            'agency_id'       => $otherAgency->id,
            'company_name'    => 'Other Co',
            'contact_user_id' => $otherContact->id,
        ]);

        $this->actingAs($admin)->getJson("/api/v1/clients/{$otherClient->id}")
            ->assertNotFound();
    }
}
