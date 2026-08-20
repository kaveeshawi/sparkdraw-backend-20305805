<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\PasswordSetupToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordSetupTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $email = 'invite@test.com'): User
    {
        $agency = Agency::create([
            'name'         => 'Test Agency',
            'domain_slug'  => 'test-agency-ps01',
            'brand_colors' => ['primary' => '#802AEE', 'light' => '#f3e8ff'],
        ]);

        return User::create([
            'agency_id' => $agency->id,
            'role'      => 'member',
            'name'      => 'Invited User',
            'email'     => $email,
            'password'  => Hash::make('temporary-password'),
        ]);
    }

    private function createToken(User $user, ?\DateTimeInterface $expiresAt = null, ?\DateTimeInterface $usedAt = null): string
    {
        $plain = Str::random(64);

        PasswordSetupToken::create([
            'user_id'    => $user->id,
            'token'      => hash('sha256', $plain),
            'expires_at' => $expiresAt ?? now()->addHours(48),
            'used_at'    => $usedAt,
        ]);

        return $plain;
    }

    public function test_set_password_succeeds_once_then_rejects_reuse(): void
    {
        $user = $this->createUser();
        $plain = $this->createToken($user);

        $response = $this->postJson('/api/v1/password-setup', [
            'token'                => $plain,
            'password'             => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));

        $token = PasswordSetupToken::where('user_id', $user->id)->first();
        $this->assertNotNull($token->used_at);

        $this->postJson('/api/v1/password-setup', [
            'token'                => $plain,
            'password'             => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertStatus(410)
            ->assertJsonPath('success', false);
    }

    public function test_expired_token_cannot_set_password(): void
    {
        $user = $this->createUser();
        $plain = $this->createToken($user, now()->subHour());

        $this->postJson('/api/v1/password-setup', [
            'token'                => $plain,
            'password'             => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertStatus(410)
            ->assertJsonPath('success', false);

        $this->assertTrue(Hash::check('temporary-password', $user->fresh()->password));
    }

    public function test_validate_returns_email_and_name_for_valid_token(): void
    {
        $user = $this->createUser('valid@test.com');
        $plain = $this->createToken($user);

        $this->getJson("/api/v1/password-setup/validate?token={$plain}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'valid@test.com')
            ->assertJsonPath('data.name', 'Invited User');
    }

    public function test_validate_returns_410_for_invalid_token(): void
    {
        $this->getJson('/api/v1/password-setup/validate?token=not-a-real-token')
            ->assertStatus(410)
            ->assertJsonPath('success', false);
    }

    public function test_validate_returns_410_for_expired_token(): void
    {
        $user = $this->createUser();
        $plain = $this->createToken($user, now()->subHour());

        $this->getJson("/api/v1/password-setup/validate?token={$plain}")
            ->assertStatus(410)
            ->assertJsonPath('success', false);
    }

    public function test_validate_returns_410_for_used_token(): void
    {
        $user = $this->createUser();
        $plain = $this->createToken($user, now()->addHour(), now());

        $this->getJson("/api/v1/password-setup/validate?token={$plain}")
            ->assertStatus(410)
            ->assertJsonPath('success', false);
    }
}
