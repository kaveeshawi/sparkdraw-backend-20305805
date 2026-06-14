<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createAgencyWithUser(string $role = 'admin'): array
    {
        $agency = Agency::create([
            'name'         => 'Test Agency',
            'domain_slug'  => 'test-agency-abc12',
            'brand_colors' => ['primary' => '#802AEE', 'light' => '#f3e8ff'],
        ]);

        $user = User::create([
            'agency_id' => $agency->id,
            'role'      => $role,
            'name'      => ucfirst($role) . ' User',
            'email'     => "{$role}@test.com",
            'password'  => Hash::make('password'),
        ]);

        return compact('agency', 'user');
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_user_can_register_and_creates_agency(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'agency_name'           => 'Bright Digital',
            'name'                  => 'Jane Smith',
            'email'                 => 'jane@bright.com',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'user' => ['id', 'name', 'email', 'role', 'agency'],
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'admin');

        $this->assertDatabaseHas('agencies', ['name' => 'Bright Digital']);
        $this->assertDatabaseHas('users', ['email' => 'jane@bright.com', 'role' => 'admin']);
    }

    public function test_user_can_login_and_receives_token(): void
    {
        $this->createAgencyWithUser('admin');

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'admin@test.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => ['token', 'user' => ['id', 'name', 'email', 'role']],
            ])
            ->assertJsonPath('success', true);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_wrong_password_returns_401(): void
    {
        $this->createAgencyWithUser('admin');

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'admin@test.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_client_cannot_access_admin_routes(): void
    {
        ['user' => $client] = $this->createAgencyWithUser('client');

        // Client tries to access agency settings — must get 403
        $response = $this->actingAs($client)->getJson('/api/v1/agency');

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_member_cannot_access_pm_routes(): void
    {
        ['user' => $member] = $this->createAgencyWithUser('member');

        // Member tries to invite a user — admin only, must get 403
        $response = $this->actingAs($member)->postJson('/api/v1/team/invite', [
            'name'  => 'New User',
            'email' => 'new@test.com',
            'role'  => 'member',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_user_belongs_to_correct_agency_scope(): void
    {
        // Create two separate agencies with an admin in each
        ['agency' => $agencyA, 'user' => $adminA] = $this->createAgencyWithUser('admin');

        $agencyB = Agency::create([
            'name'        => 'Agency B',
            'domain_slug' => 'agency-b-xyz99',
            'brand_colors'=> ['primary' => '#000000'],
        ]);
        User::create([
            'agency_id' => $agencyB->id,
            'role'      => 'admin',
            'name'      => 'Admin B',
            'email'     => 'adminb@test.com',
            'password'  => Hash::make('password'),
        ]);

        // Admin A fetches their team — must only see Agency A users
        $response = $this->actingAs($adminA)->getJson('/api/v1/team');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $returnedEmails = collect($response->json('data'))->pluck('email')->all();

        // Admin A should see admin@test.com (themselves)
        $this->assertContains('admin@test.com', $returnedEmails);

        // Admin A must NOT see Agency B users — tenant isolation check
        $this->assertNotContains('adminb@test.com', $returnedEmails);
    }
}
