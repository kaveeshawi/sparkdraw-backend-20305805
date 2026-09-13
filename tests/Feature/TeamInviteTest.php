<?php

namespace Tests\Feature;

use App\Mail\TeamInviteMail;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamInviteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function createAgencyWithUser(string $role = 'admin'): array
    {
        $agency = Agency::create([
            'name'         => 'Test Agency',
            'domain_slug'  => 'test-agency-inv01',
            'brand_colors' => ['primary' => '#802AEE', 'light' => '#f3e8ff'],
        ]);

        $user = User::create([
            'agency_id' => $agency->id,
            'role'      => $role,
            'name'      => ucfirst($role) . ' User',
            'email'     => "{$role}-invite@test.com",
            'password'  => Hash::make('password'),
        ]);

        return compact('agency', 'user');
    }

    private function validInvitePayload(array $overrides = []): array
    {
        return array_merge([
            'name'             => 'Alex Rivera',
            'email'            => 'alex@example.com',
            'role'             => 'member',
            'department'       => 'Design',
            'employment_type'  => 'full_time',
            'phone'            => '+94771234567',
            'job_title'        => 'UI Designer',
        ], $overrides);
    }

    public function test_invite_returns_temporary_password_credentials(): void
    {
        ['user' => $admin] = $this->createAgencyWithUser('admin');

        $response = $this->actingAs($admin)->postJson('/api/v1/team/invite', $this->validInvitePayload([
            'send_email' => true,
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.invite_status', 'active')
            ->assertJsonPath('data.email', 'alex@example.com')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'role',
                    'temporary_password',
                ],
            ]);

        $password = $response->json('data.temporary_password');
        $this->assertNotEmpty($password);
        $this->assertGreaterThanOrEqual(8, strlen($password));

        $member = User::where('email', 'alex@example.com')->first();
        $this->assertTrue(Hash::check($password, $member->password));

        Mail::assertSent(TeamInviteMail::class, function (TeamInviteMail $mail) use ($password) {
            return $mail->temporaryPassword === $password
                && str_contains($mail->loginUrl, '/login');
        });

        $this->postJson('/api/v1/login', [
            'email' => 'alex@example.com',
            'password' => $password,
        ])->assertOk()->assertJsonPath('data.user.role', 'member');
    }

    public function test_invite_without_email_still_returns_credentials(): void
    {
        ['user' => $admin] = $this->createAgencyWithUser('admin');

        $response = $this->actingAs($admin)->postJson('/api/v1/team/invite', $this->validInvitePayload([
            'send_email' => false,
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.invite_status', 'active')
            ->assertJsonStructure(['data' => ['temporary_password', 'email']]);

        Mail::assertNothingSent();
    }

    public function test_invite_stores_profile_fields_and_sets_availability_offline(): void
    {
        ['user' => $admin] = $this->createAgencyWithUser('admin');

        $this->actingAs($admin)->postJson('/api/v1/team/invite', $this->validInvitePayload([
            'email' => 'profile@example.com',
        ]))->assertStatus(201);

        $user = User::where('email', 'profile@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Design', $user->department);
        $this->assertSame('full_time', $user->employment_type);
        $this->assertSame('offline', $user->availability);
        $this->assertSame('+94771234567', $user->phone);
        $this->assertSame('UI Designer', $user->job_title);
    }

    public function test_resend_invite_rate_limited(): void
    {
        ['user' => $admin] = $this->createAgencyWithUser('admin');

        $inviteResponse = $this->actingAs($admin)->postJson('/api/v1/team/invite', $this->validInvitePayload());
        $memberId = $inviteResponse->json('data.id');

        $this->actingAs($admin)->postJson("/api/v1/team/{$memberId}/resend-invite")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['temporary_password', 'email']]);

        $this->actingAs($admin)->postJson("/api/v1/team/{$memberId}/resend-invite")
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }

    public function test_member_can_view_own_team_profile(): void
    {
        ['agency' => $agency, 'user' => $admin] = $this->createAgencyWithUser('admin');

        $member = User::create([
            'agency_id' => $agency->id,
            'role' => 'member',
            'name' => 'Portal Member',
            'email' => 'portal-member@test.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($member)->getJson("/api/v1/team/{$member->id}")
            ->assertOk()
            ->assertJsonPath('data.email', 'portal-member@test.com');

        $this->actingAs($member)->getJson("/api/v1/team/{$admin->id}")
            ->assertStatus(403);
    }

    public function test_non_admin_cannot_invite(): void
    {
        ['user' => $member] = $this->createAgencyWithUser('member');

        $this->actingAs($member)->postJson('/api/v1/team/invite', $this->validInvitePayload())
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
