<?php

namespace Tests\Feature;

use App\Mail\TeamInviteMail;
use App\Models\Agency;
use App\Models\PasswordSetupToken;
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

    private function assertResponseHasNoCredentialKeys(array $payload): void
    {
        $forbidden = ['temporary_password', 'password', 'credentials_message'];

        foreach ($forbidden as $key) {
            $this->assertArrayNotHasKey($key, $payload, "Response must not contain key: {$key}");
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            foreach ($forbidden as $key) {
                $this->assertArrayNotHasKey($key, $payload['data'], "Response data must not contain key: {$key}");
            }
        }
    }

    public function test_invite_returns_invite_url_not_password(): void
    {
        ['user' => $admin] = $this->createAgencyWithUser('admin');

        $response = $this->actingAs($admin)->postJson('/api/v1/team/invite', $this->validInvitePayload([
            'send_email' => true,
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.invite_status', 'invite_pending')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'role',
                    'department',
                    'employment_type',
                    'availability',
                    'phone',
                    'job_title',
                    'invite_url',
                ],
            ]);

        $inviteUrl = $response->json('data.invite_url');
        $this->assertStringContainsString('/set-password?token=', $inviteUrl);

        $this->assertDatabaseCount('password_setup_tokens', 1);

        Mail::assertSent(TeamInviteMail::class, function (TeamInviteMail $mail) {
            return str_contains($mail->inviteUrl, '/set-password?token=');
        });

        $this->assertResponseHasNoCredentialKeys($response->json());
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
            ->assertJsonStructure(['data' => ['invite_url']]);

        $this->actingAs($admin)->postJson("/api/v1/team/{$memberId}/resend-invite")
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }

    public function test_response_json_must_not_contain_credential_keys(): void
    {
        ['user' => $admin] = $this->createAgencyWithUser('admin');

        $inviteResponse = $this->actingAs($admin)->postJson('/api/v1/team/invite', $this->validInvitePayload([
            'email' => 'secure@example.com',
        ]));

        $this->assertResponseHasNoCredentialKeys($inviteResponse->json());

        $memberId = $inviteResponse->json('data.id');

        $resendResponse = $this->actingAs($admin)->postJson("/api/v1/team/{$memberId}/resend-invite");

        $this->assertResponseHasNoCredentialKeys($resendResponse->json());
    }

    public function test_invite_without_email_is_invite_not_sent(): void
    {
        ['user' => $admin] = $this->createAgencyWithUser('admin');

        $response = $this->actingAs($admin)->postJson('/api/v1/team/invite', $this->validInvitePayload([
            'send_email' => false,
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.invite_status', 'invite_not_sent');

        $this->assertDatabaseCount('password_setup_tokens', 0);
    }

    public function test_non_admin_cannot_invite(): void
    {
        ['user' => $member] = $this->createAgencyWithUser('member');

        $this->actingAs($member)->postJson('/api/v1/team/invite', $this->validInvitePayload())
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
