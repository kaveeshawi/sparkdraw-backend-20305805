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

    private const PROVIDERS = [
        'mail_smtp',
        'google_meet',
        'microsoft_teams',
        'zoom',
        'stripe',
        'paypal',
        'wise',
        'slack',
        'google_drive',
    ];

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
            'client_secret',
            'api_token',
            'api_key',
            'webhook_url',
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

    public function test_index_materializes_all_providers_as_disconnected(): void
    {
        $agency = $this->makeAgency('agency-int-001');
        $admin  = $this->makeUser($agency);

        $response = $this->actingAs($admin)->getJson('/api/v1/integrations');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(count(self::PROVIDERS), 'data');

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
            'provider'  => 'mail_smtp',
            'status'    => 'connected',
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
            'provider'  => 'mail_smtp',
            'status'    => 'disconnected',
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

    public function test_connect_paypal_wise_meetings_and_slack(): void
    {
        $agency = $this->makeAgency('agency-int-004');
        $admin  = $this->makeUser($agency);

        $this->actingAs($admin)->postJson('/api/v1/integrations/paypal/connect', [
            'credentials' => [
                'client_id'     => 'paypal-client',
                'client_secret' => 'paypal-secret',
                'mode'          => 'sandbox',
            ],
        ])->assertOk()->assertJsonPath('data.status', 'connected');

        $this->actingAs($admin)->postJson('/api/v1/integrations/wise/connect', [
            'credentials' => [
                'api_token'  => 'wise-token',
                'profile_id' => '12345',
            ],
        ])->assertOk()->assertJsonPath('data.status', 'connected');

        $this->actingAs($admin)->postJson('/api/v1/integrations/google_meet/connect', [
            'credentials' => [
                'workspace_email' => 'meet@agency.com',
                'api_key'         => 'gm-key',
                'calendar_id'     => 'primary',
            ],
        ])->assertOk()->assertJsonPath('data.status', 'connected');

        $this->actingAs($admin)->postJson('/api/v1/integrations/microsoft_teams/connect', [
            'credentials' => [
                'tenant_id'              => 'tenant-1',
                'webhook_or_meeting_url' => 'https://teams.microsoft.com/l/meetup-join/abc',
            ],
        ])->assertOk()->assertJsonPath('data.status', 'connected');

        $this->actingAs($admin)->postJson('/api/v1/integrations/zoom/connect', [
            'credentials' => [
                'account_id'    => 'acc-1',
                'client_id'     => 'zoom-client',
                'client_secret' => 'zoom-secret',
            ],
        ])->assertOk()->assertJsonPath('data.status', 'connected');

        $this->actingAs($admin)->postJson('/api/v1/integrations/slack/connect', [
            'credentials' => [
                'webhook_url' => 'https://hooks.slack.com/services/T/B/xxx',
                'channel'     => '#alerts',
            ],
        ])->assertOk()->assertJsonPath('data.status', 'connected');

        $this->actingAs($admin)->postJson('/api/v1/integrations/google_drive/connect', [
            'credentials' => [
                'folder_id' => 'folder-1',
                'api_key'   => 'drive-key',
            ],
        ])->assertOk()->assertJsonPath('data.status', 'connected');

        $index = $this->actingAs($admin)->getJson('/api/v1/integrations');
        $index->assertOk();
        $this->assertJsonHasNoCredentialKeys($index->getContent());
        $this->assertStringNotContainsString('paypal-secret', $index->getContent());
        $this->assertStringNotContainsString('wise-token', $index->getContent());
    }

    public function test_meeting_link_requires_connected_provider(): void
    {
        $agency = $this->makeAgency('agency-int-meet');
        $admin  = $this->makeUser($agency);

        $this->actingAs($admin)->postJson('/api/v1/meetings', [
            'provider' => 'google_meet',
            'title'    => 'Kickoff',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/api/v1/integrations/google_meet/connect', [
            'credentials' => [
                'workspace_email' => 'meet@agency.com',
            ],
        ])->assertOk();

        $this->actingAs($admin)->postJson('/api/v1/meetings', [
            'provider' => 'google_meet',
            'title'    => 'Kickoff',
        ])->assertOk()
            ->assertJsonPath('data.provider', 'google_meet')
            ->assertJsonPath('success', true);

        $url = $this->actingAs($admin)->postJson('/api/v1/meetings', [
            'provider' => 'google_meet',
        ])->json('data.url');

        $this->assertStringStartsWith('https://meet.google.com/', $url);
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

        $this->actingAs($admin)->postJson('/api/v1/integrations/notarealprovider/connect', [
            'credentials' => ['token' => 'x'],
        ])->assertStatus(404);
    }

    public function test_oauth_start_requires_real_app_credentials(): void
    {
        $agency = $this->makeAgency('agency-oauth-001');
        $admin  = $this->makeUser($agency);

        $this->actingAs($admin)->getJson('/api/v1/integrations/google_meet/oauth/start')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_oauth_start_returns_authorize_url_when_configured(): void
    {
        config([
            'integrations.demo_oauth' => false,
            'integrations.oauth.google_meet.client_id' => 'google-client-id',
            'integrations.oauth.google_meet.client_secret' => 'google-client-secret',
        ]);

        $agency = $this->makeAgency('agency-oauth-real');
        $admin  = $this->makeUser($agency);

        $start = $this->actingAs($admin)->getJson('/api/v1/integrations/google_meet/oauth/start');
        $start->assertOk()
            ->assertJsonPath('data.mode', 'oauth');

        $url = $start->json('data.authorize_url');
        $this->assertNotEmpty($url);
        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString('client_id=google-client-id', $url);
        $this->assertStringContainsString('state=', $url);
    }

    public function test_demo_oauth_only_when_explicitly_enabled(): void
    {
        config(['integrations.demo_oauth' => true]);

        $agency = $this->makeAgency('agency-oauth-demo');
        $admin  = $this->makeUser($agency);

        $start = $this->actingAs($admin)->getJson('/api/v1/integrations/google_meet/oauth/start');
        $start->assertOk()->assertJsonPath('data.mode', 'demo');

        $complete = $this->actingAs($admin)->postJson('/api/v1/integrations/google_meet/oauth/demo', [
            'state' => $start->json('data.state'),
        ]);

        $complete->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.connection_method', 'oauth');
    }

    public function test_oauth_start_for_mail_smtp_rejected(): void
    {
        $agency = $this->makeAgency('agency-oauth-002');
        $admin  = $this->makeUser($agency);

        $this->actingAs($admin)->getJson('/api/v1/integrations/mail_smtp/oauth/start')
            ->assertStatus(422);
    }

    public function test_index_includes_oauth_metadata(): void
    {
        $agency = $this->makeAgency('agency-oauth-003');
        $admin  = $this->makeUser($agency);

        $response = $this->actingAs($admin)->getJson('/api/v1/integrations');
        $response->assertOk();

        $meet = collect($response->json('data'))->firstWhere('provider', 'google_meet');
        $this->assertTrue($meet['supports_oauth']);
        $this->assertFalse($meet['oauth_ready']);
        $this->assertSame('manual', $meet['auth_mode']);
        $this->assertNotEmpty($meet['setup_hint']);

        $smtp = collect($response->json('data'))->firstWhere('provider', 'mail_smtp');
        $this->assertFalse($smtp['supports_oauth']);
        $this->assertSame('manual', $smtp['auth_mode']);
    }

    public function test_google_meet_requires_workspace_email(): void
    {
        $agency = $this->makeAgency('agency-int-ggg');
        $admin  = $this->makeUser($agency);

        $this->actingAs($admin)->postJson('/api/v1/integrations/google_meet/connect', [
            'credentials' => [],
        ])->assertStatus(422);
    }
}
