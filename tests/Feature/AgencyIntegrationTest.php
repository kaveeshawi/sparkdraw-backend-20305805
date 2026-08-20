<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AgencyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDERS = ['mail_smtp', 'google_meet', 'microsoft_teams', 'stripe'];

    private function makeAgency(string $slug): Agency
    {
        return Agency::create([
            'name'         => 'Agency ' . $slug,
            'domain_slug'  => $slug,
            'brand_colors' => ['primary' => '#802AEE'],
        ]);
    }

    private function makeUser(Agency $agency, string $role = 'admin'): User
    {
        return User::create([
            'agency_id' => $agency->id,
            'role'      => $role,
            'name'      => ucfirst($role) . ' User',
            'email'     => "{$role}-{$agency->domain_slug}@test.com",
            'password'  => Hash::make('password'),
        ]);
    }

    private function mailSmtpPayload(array $overrides = []): array
    {
        return array_merge([
            'host'         => 'smtp.example.com',
            'port'         => 587,
            'username'     => 'mailer@example.com',
            'password'     => 'super-secret-smtp-pass',
            'encryption'   => 'tls',
            'from_address' => 'noreply@example.com',
            'from_name'    => 'Example Agency',
        ], $overrides);
    }

    private function assertJsonHasNoCredentialKeys(string $json): void
    {
        $forbidden = [
            'credentials',
            'password',
            'secret_key',
            'publishable_key',
            'host',
            'username',
            'port',
            'encryption',
            'from_address',
            'from_name',
        ];

        foreach ($forbidden as $key) {
            $this->assertStringNotContainsString(
                '"' . $key . '"',
                $json,
                "GET response must not contain key: {$key}"
            );
        }
    }

    public function test_index_materializes_all_four_providers_as_disconnected(): void
    {
        $agency = $this->makeAgency('agency-int-001');
        $admin  = $this->makeUser($agency);

        $response = $this->actingAs($admin)->getJson('/api/v1/integrations');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(4, 'data');

        $providers = collect($response->json('data'))->pluck('provider')->all();
        $this->assertSame(self::PROVIDERS, $providers);

        foreach ($response->json('data') as $row) {
            $this->assertSame('disconnected', $row['status']);
            $this->assertNull($row['connected_at']);
        }

        $this->assertJsonHasNoCredentialKeys($response->getContent());
    }

    public function test_connect_mail_smtp_happy_path_and_disconnect(): void
    {
        $agency = $this->makeAgency('agency-int-002');
        $admin  = $this->makeUser($agency);

        $connect = $this->actingAs($admin)->postJson('/api/v1/integrations/mail_smtp/connect', [
            'credentials' => $this->mailSmtpPayload(),
        ]);

        $connect->assertStatus(200)
            ->assertJsonPath('data.provider', 'mail_smtp')
            ->assertJsonPath('data.status', 'connected');

        $this->assertDatabaseHas('agency_integrations', [
            'agency_id' => $agency->id,
            'provider'    => 'mail_smtp',
            'status'      => 'connected',
        ]);

        $stored = AgencyIntegration::withoutAgencyScope()
            ->where('agency_id', $agency->id)
            ->where('provider', 'mail_smtp')
            ->first();

        $this->assertNotNull($stored->credentials);
        $this->assertSame('smtp.example.com', $stored->credentials['host']);
        $this->assertSame('super-secret-smtp-pass', $stored->credentials['password']);

        $index = $this->actingAs($admin)->getJson('/api/v1/integrations');
        $index->assertOk();
        $mailRow = collect($index->json('data'))->firstWhere('provider', 'mail_smtp');
        $this->assertSame('connected', $mailRow['status']);
        $this->assertJsonHasNoCredentialKeys($index->getContent());

        $disconnect = $this->actingAs($admin)->postJson('/api/v1/integrations/mail_smtp/disconnect');
        $disconnect->assertOk()
            ->assertJsonPath('data.provider', 'mail_smtp')
            ->assertJsonPath('data.status', 'disconnected');

        $this->assertDatabaseHas('agency_integrations', [
            'agency_id' => $agency->id,
            'provider'    => 'mail_smtp',
            'status'      => 'disconnected',
        ]);

        $storedAfter = AgencyIntegration::withoutAgencyScope()
            ->where('agency_id', $agency->id)
            ->where('provider', 'mail_smtp')
            ->first();

        $this->assertNull($storedAfter->credentials);
    }

    public function test_connect_stripe_stores_keys_but_get_never_returns_secrets(): void
    {
        $agency = $this->makeAgency('agency-int-003');
        $admin  = $this->makeUser($agency);

        $this->actingAs($admin)->postJson('/api/v1/integrations/stripe/connect', [
            'credentials' => [
                'publishable_key' => 'pk_test_abc123',
                'secret_key'      => 'sk_test_xyz789',
            ],
        ])->assertOk()
            ->assertJsonPath('data.status', 'connected');

        $stored = AgencyIntegration::withoutAgencyScope()
            ->where('agency_id', $agency->id)
            ->where('provider', 'stripe')
            ->first();

        $this->assertSame('pk_test_abc123', $stored->credentials['publishable_key']);
        $this->assertSame('sk_test_xyz789', $stored->credentials['secret_key']);

        $index = $this->actingAs($admin)->getJson('/api/v1/integrations');
        $index->assertOk();
        $this->assertJsonHasNoCredentialKeys($index->getContent());

        $stripeRow = collect($index->json('data'))->firstWhere('provider', 'stripe');
        $this->assertSame('connected', $stripeRow['status']);
        $this->assertArrayNotHasKey('credentials', $stripeRow);
    }

    public function test_stub_connect_google_meet_and_microsoft_teams_without_oauth(): void
    {
        $agency = $this->makeAgency('agency-int-004');
        $admin  = $this->makeUser($agency);

        foreach (['google_meet', 'microsoft_teams'] as $provider) {
            $this->actingAs($admin)->postJson("/api/v1/integrations/{$provider}/connect", [
                'credentials' => [],
            ])->assertOk()
                ->assertJsonPath('data.provider', $provider)
                ->assertJsonPath('data.status', 'connected');

            $stored = AgencyIntegration::withoutAgencyScope()
                ->where('agency_id', $agency->id)
                ->where('provider', $provider)
                ->first();

            $this->assertSame(['connected' => true], $stored->credentials);
        }
    }

    public function test_get_after_connect_never_contains_credentials_in_json(): void
    {
        $agency = $this->makeAgency('agency-int-005');
        $admin  = $this->makeUser($agency);

        $this->actingAs($admin)->postJson('/api/v1/integrations/mail_smtp/connect', [
            'credentials' => $this->mailSmtpPayload(['password' => 'leak-test-password']),
        ])->assertOk();

        $this->actingAs($admin)->postJson('/api/v1/integrations/stripe/connect', [
            'credentials' => [
                'publishable_key' => 'pk_leak',
                'secret_key'      => 'sk_leak_secret',
            ],
        ])->assertOk();

        $response = $this->actingAs($admin)->getJson('/api/v1/integrations');
        $response->assertOk();

        $content = $response->getContent();
        $this->assertJsonHasNoCredentialKeys($content);
        $this->assertStringNotContainsString('leak-test-password', $content);
        $this->assertStringNotContainsString('sk_leak_secret', $content);
        $this->assertStringNotContainsString('smtp.example.com', $content);
    }

    public function test_agency_b_cannot_see_agency_a_connected_integration_status(): void
    {
        $agencyA = $this->makeAgency('agency-int-aaa');
        $adminA  = $this->makeUser($agencyA);

        $agencyB = $this->makeAgency('agency-int-bbb');
        $adminB  = $this->makeUser($agencyB);

        $this->actingAs($adminA)->postJson('/api/v1/integrations/mail_smtp/connect', [
            'credentials' => $this->mailSmtpPayload(),
        ])->assertOk();

        $responseB = $this->actingAs($adminB)->getJson('/api/v1/integrations');
        $responseB->assertOk()
            ->assertJsonPath('success', true);

        $mailRowB = collect($responseB->json('data'))->firstWhere('provider', 'mail_smtp');
        $this->assertSame('disconnected', $mailRowB['status']);
        $this->assertJsonHasNoCredentialKeys($responseB->getContent());
        $this->assertStringNotContainsString('super-secret-smtp-pass', $responseB->getContent());

        $storedA = AgencyIntegration::withoutAgencyScope()
            ->where('agency_id', $agencyA->id)
            ->where('provider', 'mail_smtp')
            ->first();

        $this->assertSame('connected', $storedA->status);
    }

    public function test_agency_b_disconnect_does_not_affect_agency_a_integration(): void
    {
        $agencyA = $this->makeAgency('agency-int-ccc');
        $adminA  = $this->makeUser($agencyA);

        $agencyB = $this->makeAgency('agency-int-ddd');
        $adminB  = $this->makeUser($agencyB);

        $this->actingAs($adminA)->postJson('/api/v1/integrations/mail_smtp/connect', [
            'credentials' => $this->mailSmtpPayload(),
        ])->assertOk();

        $disconnectB = $this->actingAs($adminB)->postJson('/api/v1/integrations/mail_smtp/disconnect');
        $disconnectB->assertOk()
            ->assertJsonPath('data.status', 'disconnected');

        $storedA = AgencyIntegration::withoutAgencyScope()
            ->where('agency_id', $agencyA->id)
            ->where('provider', 'mail_smtp')
            ->first();

        $this->assertSame('connected', $storedA->status);
        $this->assertNotNull($storedA->credentials);
        $this->assertSame('super-secret-smtp-pass', $storedA->credentials['password']);
    }

    public function test_non_admin_cannot_access_integrations(): void
    {
        $agency = $this->makeAgency('agency-int-eee');
        $pm     = $this->makeUser($agency, 'pm');

        $this->actingAs($pm)->getJson('/api/v1/integrations')->assertStatus(403);
        $this->actingAs($pm)->postJson('/api/v1/integrations/mail_smtp/connect', [
            'credentials' => $this->mailSmtpPayload(),
        ])->assertStatus(403);
        $this->actingAs($pm)->postJson('/api/v1/integrations/mail_smtp/disconnect')->assertStatus(403);
    }

    public function test_connect_rejects_unknown_provider(): void
    {
        $agency = $this->makeAgency('agency-int-fff');
        $admin  = $this->makeUser($agency);

        $this->actingAs($admin)->postJson('/api/v1/integrations/paypal/connect', [
            'credentials' => ['token' => 'x'],
        ])->assertStatus(404);
    }
}
