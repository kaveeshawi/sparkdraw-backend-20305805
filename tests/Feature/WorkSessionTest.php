<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkSessionTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createAgencyWithUser(string $role = 'member'): array
    {
        $agency = Agency::create([
            'name'         => 'Test Agency',
            'domain_slug'  => 'test-agency-ws01',
            'brand_colors' => ['primary' => '#802AEE', 'light' => '#f3e8ff'],
        ]);

        $user = User::create([
            'agency_id'    => $agency->id,
            'role'         => $role,
            'name'         => ucfirst($role) . ' User',
            'email'        => "{$role}-ws@test.com",
            'password'     => Hash::make('password'),
            'availability' => 'offline',
        ]);

        return compact('agency', 'user');
    }

    private function clockIn(User $user)
    {
        return $this->actingAs($user)->postJson('/api/v1/work-sessions/clock-in');
    }

    // ── Clock in / out ────────────────────────────────────────────────────────

    public function test_clock_in_sets_available_and_opens_session(): void
    {
        ['user' => $user] = $this->createAgencyWithUser('member');

        $response = $this->clockIn($user);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.availability', 'available')
            ->assertJsonStructure([
                'data' => [
                    'session' => ['id', 'clock_in_at', 'clock_out_at'],
                    'availability',
                ],
            ])
            ->assertJsonPath('data.session.clock_out_at', null);

        $this->assertDatabaseHas('work_sessions', [
            'user_id'      => $user->id,
            'agency_id'    => $user->agency_id,
            'clock_out_at' => null,
        ]);

        $this->assertSame('available', $user->fresh()->availability);
    }

    public function test_clock_out_sets_offline_and_closes_session(): void
    {
        ['user' => $user] = $this->createAgencyWithUser('member');

        $this->clockIn($user);

        $response = $this->actingAs($user)->postJson('/api/v1/work-sessions/clock-out');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.availability', 'offline')
            ->assertJsonStructure([
                'data' => [
                    'session' => ['id', 'clock_in_at', 'clock_out_at'],
                    'availability',
                ],
            ]);

        $session = WorkSession::where('user_id', $user->id)->first();
        $this->assertNotNull($session->clock_out_at);
        $this->assertSame('offline', $user->fresh()->availability);
    }

    public function test_clock_in_while_already_open_returns_422(): void
    {
        ['user' => $user] = $this->createAgencyWithUser('member');

        $this->clockIn($user)->assertOk();

        $response = $this->clockIn($user);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_clock_out_without_open_session_returns_422(): void
    {
        ['user' => $user] = $this->createAgencyWithUser('member');

        $response = $this->actingAs($user)->postJson('/api/v1/work-sessions/clock-out');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_current_returns_open_session_or_null(): void
    {
        ['user' => $user] = $this->createAgencyWithUser('member');

        $this->actingAs($user)->getJson('/api/v1/work-sessions/current')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->clockIn($user);

        $this->actingAs($user)->getJson('/api/v1/work-sessions/current')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'clock_in_at', 'clock_out_at']])
            ->assertJsonPath('data.clock_out_at', null);
    }

    // ── Availability overrides ────────────────────────────────────────────────

    public function test_patch_offline_availability_returns_422(): void
    {
        ['user' => $user] = $this->createAgencyWithUser('member');
        $this->clockIn($user);

        $response = $this->actingAs($user)->patchJson('/api/v1/me/availability', [
            'availability' => 'offline',
        ]);

        $response->assertStatus(422);
    }

    public function test_patch_available_while_not_clocked_in_returns_422(): void
    {
        ['user' => $user] = $this->createAgencyWithUser('member');

        $response = $this->actingAs($user)->patchJson('/api/v1/me/availability', [
            'availability' => 'available',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_patch_away_and_busy_only_while_clocked_in(): void
    {
        ['user' => $user] = $this->createAgencyWithUser('member');

        // Not clocked in — away should fail
        $this->actingAs($user)->patchJson('/api/v1/me/availability', ['availability' => 'away'])
            ->assertStatus(422);

        $this->actingAs($user)->patchJson('/api/v1/me/availability', ['availability' => 'busy'])
            ->assertStatus(422);

        // Clocked in — away and busy succeed
        $this->clockIn($user);

        $this->actingAs($user)->patchJson('/api/v1/me/availability', ['availability' => 'away'])
            ->assertOk()
            ->assertJsonPath('data.availability', 'away');

        $this->assertSame('away', $user->fresh()->availability);

        $this->actingAs($user)->patchJson('/api/v1/me/availability', ['availability' => 'busy'])
            ->assertOk()
            ->assertJsonPath('data.availability', 'busy');

        $this->assertSame('busy', $user->fresh()->availability);
    }

    public function test_patch_available_clears_override_while_still_clocked_in(): void
    {
        ['user' => $user] = $this->createAgencyWithUser('member');

        $this->clockIn($user);

        $this->actingAs($user)->patchJson('/api/v1/me/availability', ['availability' => 'busy'])
            ->assertOk();

        $this->assertSame('busy', $user->fresh()->availability);

        $this->actingAs($user)->patchJson('/api/v1/me/availability', ['availability' => 'available'])
            ->assertOk()
            ->assertJsonPath('data.availability', 'available');

        $this->assertSame('available', $user->fresh()->availability);

        // Session should still be open
        $this->assertDatabaseHas('work_sessions', [
            'user_id'      => $user->id,
            'clock_out_at' => null,
        ]);
    }

    public function test_client_cannot_access_work_session_routes(): void
    {
        ['user' => $client] = $this->createAgencyWithUser('client');

        $this->actingAs($client)->postJson('/api/v1/work-sessions/clock-in')
            ->assertStatus(403);

        $this->actingAs($client)->patchJson('/api/v1/me/availability', ['availability' => 'away'])
            ->assertStatus(403);
    }

    public function test_work_session_uses_auth_user_agency_id(): void
    {
        ['user' => $user] = $this->createAgencyWithUser('member');

        $this->clockIn($user);

        $this->assertDatabaseHas('work_sessions', [
            'user_id'   => $user->id,
            'agency_id' => $user->agency_id,
        ]);
    }
}
