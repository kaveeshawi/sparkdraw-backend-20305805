<?php

namespace App\Services;

use App\Models\AgencyIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IntegrationOAuthService
{
    public const OAUTH_PROVIDERS = [
        'google_meet',
        'google_drive',
        'microsoft_teams',
        'zoom',
        'stripe',
        'paypal',
        'slack',
    ];

    public function supportsOAuth(string $provider): bool
    {
        return in_array($provider, self::OAUTH_PROVIDERS, true);
    }

    public function hasRealOAuthApp(string $provider): bool
    {
        $cfg = $this->providerConfig($provider);

        if (!$cfg) {
            return false;
        }

        return filled($cfg['client_id'] ?? null) && filled($cfg['client_secret'] ?? null);
    }

    public function mode(string $provider): string
    {
        if (!$this->supportsOAuth($provider)) {
            return 'manual';
        }

        if ($this->hasRealOAuthApp($provider)) {
            return 'oauth';
        }

        // Demo mode is opt-in (tests only). Never auto-fake authorize in the product UI.
        return config('integrations.demo_oauth', false) ? 'demo' : 'manual';
    }

    /**
     * @return array{mode: string, authorize_url?: string, state?: string, label?: string, setup_hint?: string}|array{error: string}
     */
    public function begin(string $provider, int $agencyId, int $userId): array
    {
        if (!$this->supportsOAuth($provider)) {
            return ['error' => 'This provider does not support one-click OAuth.'];
        }

        $mode = $this->mode($provider);

        if ($mode === 'manual') {
            return [
                'error' => 'Real OAuth is not configured. Add this provider’s Client ID and Secret to the backend .env, then try again — or enter credentials manually.',
                'setup_hint' => $this->setupHint($provider),
            ];
        }

        $state = Str::random(40);
        Cache::put($this->stateKey($state), [
            'provider'  => $provider,
            'agency_id' => $agencyId,
            'user_id'   => $userId,
            'mode'      => $mode,
        ], now()->addMinutes(15));

        if ($mode === 'demo') {
            return [
                'mode'          => 'demo',
                'state'         => $state,
                'authorize_url' => null,
                'label'         => $this->providerConfig($provider)['label'] ?? $provider,
            ];
        }

        return [
            'mode'          => 'oauth',
            'state'         => $state,
            'authorize_url' => $this->buildAuthorizeUrl($provider, $state),
            'label'         => $this->providerConfig($provider)['label'] ?? $provider,
        ];
    }

    public function setupHint(string $provider): string
    {
        return match ($provider) {
            'google_meet', 'google_drive' => 'Set GOOGLE_OAUTH_CLIENT_ID and GOOGLE_OAUTH_CLIENT_SECRET. Redirect URI: ' . $this->callbackUrl($provider),
            'microsoft_teams' => 'Set MICROSOFT_OAUTH_CLIENT_ID and MICROSOFT_OAUTH_CLIENT_SECRET. Redirect URI: ' . $this->callbackUrl($provider),
            'zoom' => 'Set ZOOM_OAUTH_CLIENT_ID and ZOOM_OAUTH_CLIENT_SECRET. Redirect URI: ' . $this->callbackUrl($provider),
            'stripe' => 'Set STRIPE_CONNECT_CLIENT_ID and STRIPE_SECRET_KEY. Redirect URI: ' . $this->callbackUrl($provider),
            'paypal' => 'Set PAYPAL_OAUTH_CLIENT_ID and PAYPAL_OAUTH_CLIENT_SECRET. Redirect URI: ' . $this->callbackUrl($provider),
            'slack' => 'Set SLACK_OAUTH_CLIENT_ID and SLACK_OAUTH_CLIENT_SECRET. Redirect URI: ' . $this->callbackUrl($provider),
            default => 'Configure OAuth client credentials in .env',
        };
    }

    /**
     * One-click demo connect (no external redirect).
     *
     * @return array{success: bool, integration?: AgencyIntegration, message?: string}
     */
    public function completeDemo(string $provider, string $state, int $agencyId, int $userId): array
    {
        $payload = Cache::pull($this->stateKey($state));

        if (!$payload
            || ($payload['provider'] ?? null) !== $provider
            || (int) ($payload['agency_id'] ?? 0) !== $agencyId
            || (int) ($payload['user_id'] ?? 0) !== $userId
        ) {
            return ['success' => false, 'message' => 'Invalid or expired OAuth state.'];
        }

        if (($payload['mode'] ?? '') !== 'demo') {
            return ['success' => false, 'message' => 'This state is not a demo OAuth session.'];
        }

        $integration = $this->storeCredentials($agencyId, $userId, $provider, $this->demoCredentials($provider));

        return ['success' => true, 'integration' => $integration];
    }

    /**
     * Handle provider callback (code + state). Returns frontend redirect URL.
     */
    public function handleCallback(string $provider, ?string $code, ?string $state, ?string $error = null): string
    {
        $frontend = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
        $base     = $frontend . '/integrations';

        if ($error) {
            return $base . '?oauth=denied&provider=' . urlencode($provider);
        }

        if (!$code || !$state) {
            return $base . '?oauth=error&provider=' . urlencode($provider) . '&reason=missing_code';
        }

        $payload = Cache::pull($this->stateKey($state));

        if (!$payload || ($payload['provider'] ?? null) !== $provider) {
            return $base . '?oauth=error&provider=' . urlencode($provider) . '&reason=invalid_state';
        }

        try {
            $tokens = $this->exchangeCode($provider, $code);
            $this->storeCredentials(
                (int) $payload['agency_id'],
                (int) $payload['user_id'],
                $provider,
                $tokens
            );

            return $base . '?oauth=connected&provider=' . urlencode($provider);
        } catch (\Throwable $e) {
            Log::warning('OAuth callback failed', [
                'provider' => $provider,
                'error'    => $e->getMessage(),
            ]);

            return $base . '?oauth=error&provider=' . urlencode($provider) . '&reason=token_exchange';
        }
    }

    public function callbackUrl(string $provider): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/v1/integrations/oauth/' . $provider . '/callback';
    }

    private function buildAuthorizeUrl(string $provider, string $state): string
    {
        $cfg = $this->providerConfig($provider);
        $params = array_merge([
            'client_id'     => $cfg['client_id'],
            'redirect_uri'  => $this->callbackUrl($provider),
            'state'         => $state,
            'response_type' => 'code',
        ], $cfg['extra_auth'] ?? []);

        $scopes = $cfg['scopes'] ?? [];
        if ($scopes !== []) {
            $scopeKey = $cfg['scope_param'] ?? 'scope';
            $params[$scopeKey] = implode(' ', $scopes);
        }

        // Stripe uses scope as single value without joining oddly
        if ($provider === 'stripe') {
            $params['scope'] = 'read_write';
            unset($params['response_type']);
            $params['response_type'] = 'code';
        }

        return ($cfg['auth_url'] ?? '') . '?' . http_build_query($params);
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCode(string $provider, string $code): array
    {
        $cfg = $this->providerConfig($provider);

        if ($provider === 'stripe') {
            $response = Http::asForm()
                ->withBasicAuth($cfg['client_secret'], '')
                ->post($cfg['token_url'], [
                    'grant_type' => 'authorization_code',
                    'code'       => $code,
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException('Stripe token exchange failed: ' . $response->body());
            }

            $data = $response->json();

            return [
                'auth_type'       => 'oauth',
                'access_token'    => $data['access_token'] ?? null,
                'refresh_token'   => $data['refresh_token'] ?? null,
                'stripe_user_id'  => $data['stripe_user_id'] ?? null,
                'publishable_key' => $data['stripe_publishable_key'] ?? null,
                'secret_key'      => $data['access_token'] ?? null,
                'scope'           => $data['scope'] ?? null,
                'token_type'      => $data['token_type'] ?? 'bearer',
            ];
        }

        if ($provider === 'slack') {
            $response = Http::asForm()->post($cfg['token_url'], [
                'client_id'     => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'code'          => $code,
                'redirect_uri'  => $this->callbackUrl($provider),
            ]);

            if (!$response->successful() || !($response->json('ok'))) {
                throw new \RuntimeException('Slack token exchange failed: ' . $response->body());
            }

            $data = $response->json();

            return [
                'auth_type'    => 'oauth',
                'access_token' => $data['access_token'] ?? null,
                'webhook_url'  => $data['incoming_webhook']['url'] ?? null,
                'channel'      => $data['incoming_webhook']['channel'] ?? null,
                'team_name'    => $data['team']['name'] ?? null,
            ];
        }

        if ($provider === 'paypal') {
            $response = Http::asForm()
                ->withBasicAuth($cfg['client_id'], $cfg['client_secret'])
                ->post($cfg['token_url'], [
                    'grant_type'   => 'authorization_code',
                    'code'         => $code,
                    'redirect_uri' => $this->callbackUrl($provider),
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException('PayPal token exchange failed: ' . $response->body());
            }

            $data = $response->json();

            return [
                'auth_type'     => 'oauth',
                'access_token'  => $data['access_token'] ?? null,
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_in'    => $data['expires_in'] ?? null,
                'client_id'     => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'mode'          => config('paypal.mode', 'sandbox'),
            ];
        }

        // Google / Microsoft / Zoom — standard OAuth2 code exchange
        $response = Http::asForm()->post($cfg['token_url'], [
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'code'          => $code,
            'redirect_uri'  => $this->callbackUrl($provider),
            'grant_type'    => 'authorization_code',
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException(ucfirst($provider) . ' token exchange failed: ' . $response->body());
        }

        $data = $response->json();

        $credentials = [
            'auth_type'     => 'oauth',
            'access_token'  => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in'    => $data['expires_in'] ?? null,
            'token_type'    => $data['token_type'] ?? 'Bearer',
            'scope'         => $data['scope'] ?? null,
            'id_token'      => $data['id_token'] ?? null,
        ];

        if ($provider === 'google_meet' || $provider === 'google_drive') {
            $email = $this->fetchGoogleEmail($credentials['access_token'] ?? '');
            $credentials['workspace_email'] = $email;
            $credentials['email'] = $email;
            if ($provider === 'google_drive') {
                $credentials['folder_id'] = 'root';
                $credentials['api_key'] = $credentials['access_token'];
            }
        }

        if ($provider === 'microsoft_teams') {
            $credentials['tenant_id'] = 'common';
            $credentials['webhook_or_meeting_url'] = 'https://teams.microsoft.com/l/meetup-join/oauth';
        }

        if ($provider === 'zoom') {
            $credentials['account_id'] = 'oauth';
            $credentials['client_id'] = $cfg['client_id'];
            $credentials['client_secret'] = $cfg['client_secret'];
        }

        return $credentials;
    }

    private function fetchGoogleEmail(?string $accessToken): ?string
    {
        if (!$accessToken) {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->get('https://www.googleapis.com/oauth2/v2/userinfo');

            if ($response->successful()) {
                return $response->json('email');
            }
        } catch (\Throwable $e) {
            Log::info('Could not fetch Google profile email', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function storeCredentials(int $agencyId, int $userId, string $provider, array $credentials): AgencyIntegration
    {
        return AgencyIntegration::updateOrCreate(
            [
                'agency_id' => $agencyId,
                'provider'  => $provider,
            ],
            [
                'status'       => 'connected',
                'credentials'  => $credentials,
                'connected_by' => $userId,
                'connected_at' => now(),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function demoCredentials(string $provider): array
    {
        $token = 'demo_' . Str::lower($provider) . '_' . Str::random(24);

        return match ($provider) {
            'google_meet' => [
                'auth_type'       => 'oauth',
                'access_token'    => $token,
                'refresh_token'   => 'demo_refresh_' . Str::random(16),
                'workspace_email' => 'demo@sparkdraw.local',
                'email'           => 'demo@sparkdraw.local',
                'calendar_id'     => 'primary',
                'demo'            => true,
            ],
            'google_drive' => [
                'auth_type'    => 'oauth',
                'access_token' => $token,
                'folder_id'    => 'demo-folder',
                'api_key'      => $token,
                'email'        => 'demo@sparkdraw.local',
                'demo'         => true,
            ],
            'microsoft_teams' => [
                'auth_type'              => 'oauth',
                'access_token'           => $token,
                'tenant_id'              => 'demo-tenant',
                'webhook_or_meeting_url' => 'https://teams.microsoft.com/l/meetup-join/demo',
                'demo'                   => true,
            ],
            'zoom' => [
                'auth_type'     => 'oauth',
                'access_token'  => $token,
                'account_id'    => 'demo-account',
                'client_id'     => 'demo-client',
                'client_secret' => 'demo-secret',
                'demo'          => true,
            ],
            'stripe' => [
                'auth_type'       => 'oauth',
                'access_token'    => $token,
                'secret_key'      => 'sk_test_demo_' . Str::random(12),
                'publishable_key' => 'pk_test_demo_' . Str::random(12),
                'stripe_user_id'  => 'acct_demo_' . Str::random(8),
                'demo'            => true,
            ],
            'paypal' => [
                'auth_type'     => 'oauth',
                'access_token'  => $token,
                'client_id'     => config('paypal.client_id') ?: 'demo_paypal_client',
                'client_secret' => config('paypal.client_secret') ?: 'demo_paypal_secret',
                'mode'          => config('paypal.mode', 'sandbox'),
                'demo'          => true,
            ],
            'slack' => [
                'auth_type'    => 'oauth',
                'access_token' => $token,
                'webhook_url'  => 'https://hooks.slack.com/services/DEMO/SPARKDRAW/' . Str::random(8),
                'channel'      => '#sparkdraw-alerts',
                'team_name'    => 'Demo Workspace',
                'demo'         => true,
            ],
            default => [
                'auth_type'    => 'oauth',
                'access_token' => $token,
                'demo'         => true,
            ],
        };
    }

    private function providerConfig(string $provider): ?array
    {
        return config("integrations.oauth.{$provider}");
    }

    private function stateKey(string $state): string
    {
        return 'integration_oauth:' . $state;
    }
}
