<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeamPresenceTest extends TestCase
{
    use RefreshDatabase;

    private function createAgencyWithUsers(): array
    {
        $agency = Agency::create([
            'name'         => 'Presence Agency',
            'domain_slug'  => 'presence-agency-01',
            'brand_colors' => ['primary' => '#802AEE', 'light' => '#f3e8ff'],
        ]);

        $other = Agency::create([
            'name'         => 'Other Agency',
            'domain_slug'  => 'presence-agency-02',
            'brand_colors' => ['primary' => '#2563eb', 'light' => '#dbeafe'],
        ]);

        $admin = User::create([
            'agency_id'    => $agency->id,
            'role'         => 'admin',
            'name'         => 'Ada Admin',
            'email'        => 'ada-presence@test.com',
            'password'     => Hash::make('password'),
            'availability' => 'available',
        ]);

        $onDuty = User::create([
            'agency_id'    => $agency->id,
            'role'         => 'member',
            'name'         => 'On Duty Dev',
            'email'        => 'onduty-presence@test.com',
            'password'     => Hash::make('password'),
            'availability' => 'busy',
        ]);

        $offline = User::create([
            'agency_id'    => $agency->id,
            'role'         => 'member',
            'name'         => 'Offline Dev',
            'email'        => 'offline-presence@test.com',
            'password'     => Hash::make('password'),
            'availability' => 'offline',
        ]);

        $available = User::create([
            'agency_id'    => $agency->id,
            'role'         => 'pm',
            'name'         => 'Available PM',
            'email'        => 'available-presence@test.com',
            'password'     => Hash::make('password'),
            'availability' => 'available',
        ]);

        $otherUser = User::create([
            'agency_id'    => $other->id,
            'role'         => 'admin',
            'name'         => 'Other Admin',
            'email'        => 'other-presence@test.com',
            'password'     => Hash::make('password'),
            'availability' => 'available',
        ]);

        WorkSession::create([
            'user_id'     => $onDuty->id,
            'agency_id'   => $agency->id,
            'clock_in_at' => now()->subHours(2),
        ]);

        WorkSession::create([
            'user_id'     => $otherUser->id,
            'agency_id'   => $other->id,
            'clock_in_at' => now()->subHour(),
        ]);

        return compact('agency', 'admin', 'onDuty', 'offline', 'available', 'otherUser');
    }

    public function test_team_presence_returns_summary_and_members(): void
    {
        ['admin' => $admin, 'onDuty' => $onDuty, 'offline' => $offline] = $this->createAgencyWithUsers();

        $response = $this->actingAs($admin)->getJson('/api/v1/time/team-presence');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.on_duty', 1)
            ->assertJsonPath('data.summary.offline', 1)
            ->assertJsonStructure([
                'data' => [
                    'summary' => ['on_duty', 'available', 'busy', 'away', 'offline'],
                    'members' => [
                        ['user_id', 'name', 'role', 'availability', 'is_clocked_in', 'clock_in_at', 'hours_today', 'hours_this_week', 'active_tasks'],
                    ],
                    'charts' => [
                        'hours_by_day' => [
                            ['date', 'label', 'hours'],
                        ],
                    ],
                ],
            ]);

        $this->assertCount(7, $response->json('data.charts.hours_by_day'));

        $members = collect($response->json('data.members'));
        $clocked = $members->firstWhere('user_id', $onDuty->id);
        $off = $members->firstWhere('user_id', $offline->id);

        $this->assertTrue($clocked['is_clocked_in']);
        $this->assertNotNull($clocked['clock_in_at']);
        $this->assertSame('busy', $clocked['availability']);

        $this->assertFalse($off['is_clocked_in']);
        $this->assertNull($off['clock_in_at']);
    }

    public function test_team_presence_sorts_clocked_in_first(): void
    {
        ['admin' => $admin, 'onDuty' => $onDuty] = $this->createAgencyWithUsers();

        $response = $this->actingAs($admin)->getJson('/api/v1/time/team-presence');
        $response->assertOk();

        $members = $response->json('data.members');
        $this->assertSame($onDuty->id, $members[0]['user_id']);
        $this->assertTrue($members[0]['is_clocked_in']);
    }

    public function test_team_presence_isolates_tenants(): void
    {
        ['admin' => $admin, 'otherUser' => $otherUser] = $this->createAgencyWithUsers();

        $response = $this->actingAs($admin)->getJson('/api/v1/time/team-presence');
        $response->assertOk();

        $ids = collect($response->json('data.members'))->pluck('user_id');
        $this->assertFalse($ids->contains($otherUser->id));
        $this->assertSame(1, $response->json('data.summary.on_duty'));
    }

    public function test_client_cannot_access_team_presence(): void
    {
        $agency = Agency::create([
            'name'         => 'Client Block Agency',
            'domain_slug'  => 'presence-client-block',
            'brand_colors' => ['primary' => '#802AEE', 'light' => '#f3e8ff'],
        ]);

        $client = User::create([
            'agency_id'    => $agency->id,
            'role'         => 'client',
            'name'         => 'Client User',
            'email'        => 'client-presence@test.com',
            'password'     => Hash::make('password'),
            'availability' => 'offline',
        ]);

        $this->actingAs($client)
            ->getJson('/api/v1/time/team-presence')
            ->assertForbidden();
    }
}
