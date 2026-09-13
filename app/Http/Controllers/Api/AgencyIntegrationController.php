<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\AgencyIntegration;
use App\Services\IntegrationOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgencyIntegrationController extends Controller
{
    use ApiResponse;

    public const PROVIDERS = [
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

    public const PAYMENT_PROVIDERS = ['paypal', 'stripe', 'wise'];

    public function __construct(
        private readonly IntegrationOAuthService $oauth,
    ) {}

    // GET /api/v1/integrations
    public function index(Request $request): JsonResponse
    {
        $agencyId = $request->user()->agency_id;

        $existing = AgencyIntegration::where('agency_id', $agencyId)
            ->get()
            ->keyBy('provider');

        $data = [];
        foreach (self::PROVIDERS as $provider) {
            $data[] = $existing->has($provider)
                ? $this->formatIntegration($existing[$provider])
                : $this->disconnectedPlaceholder($provider);
        }

        return $this->success($data);
    }

    // GET /api/v1/integrations/{provider}/oauth/start
    public function oauthStart(Request $request, string $provider): JsonResponse
    {
        if (!$this->isValidProvider($provider)) {
            return $this->notFound('Unknown integration provider.');
        }

        $result = $this->oauth->begin(
            $provider,
            $request->user()->agency_id,
            $request->user()->id
        );

        if (isset($result['error'])) {
            return $this->error($result['error'], [
                'setup_hint' => [$result['setup_hint'] ?? ''],
            ], 422);
        }

        return $this->success($result);
    }

    // POST /api/v1/integrations/{provider}/oauth/demo
    public function oauthDemo(Request $request, string $provider): JsonResponse
    {
        if (!$this->isValidProvider($provider)) {
            return $this->notFound('Unknown integration provider.');
        }

        $validated = $request->validate([
            'state' => ['required', 'string', 'max:80'],
        ]);

        $result = $this->oauth->completeDemo(
            $provider,
            $validated['state'],
            $request->user()->agency_id,
            $request->user()->id
        );

        if (empty($result['success'])) {
            return $this->error($result['message'] ?? 'Demo OAuth failed.', [], 422);
        }

        return $this->success(
            $this->formatIntegration($result['integration']),
            'Connected with one-click OAuth.'
        );
    }

    // GET /api/v1/integrations/oauth/{provider}/callback  (public)
    public function oauthCallback(Request $request, string $provider): RedirectResponse
    {
        $url = $this->oauth->handleCallback(
            $provider,
            $request->query('code'),
            $request->query('state'),
            $request->query('error')
        );

        return redirect()->away($url);
    }

    // POST /api/v1/integrations/{provider}/connect
    public function connect(Request $request, string $provider): JsonResponse
    {
        if (!$this->isValidProvider($provider)) {
            return $this->notFound('Unknown integration provider.');
        }

        $credentials = $this->validateCredentials($request, $provider);

        $integration = AgencyIntegration::updateOrCreate(
            [
                'agency_id' => $request->user()->agency_id,
                'provider'  => $provider,
            ],
            [
                'status'       => 'connected',
                'credentials'  => $credentials,
                'connected_by' => $request->user()->id,
                'connected_at' => now(),
            ]
        );

        return $this->success($this->formatIntegration($integration), 'Integration connected.');
    }

    // POST /api/v1/integrations/{provider}/disconnect
    public function disconnect(Request $request, string $provider): JsonResponse
    {
        if (!$this->isValidProvider($provider)) {
            return $this->notFound('Unknown integration provider.');
        }

        $integration = AgencyIntegration::firstOrCreate(
            [
                'agency_id' => $request->user()->agency_id,
                'provider'  => $provider,
            ],
            [
                'status'       => 'disconnected',
                'credentials'  => null,
                'connected_by' => null,
                'connected_at' => null,
            ]
        );

        $integration->update([
            'status'       => 'disconnected',
            'credentials'  => null,
            'connected_by' => null,
            'connected_at' => null,
        ]);

        return $this->success($this->formatIntegration($integration->fresh()), 'Integration disconnected.');
    }

    private function isValidProvider(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true);
    }

    private function validateCredentials(Request $request, string $provider): array
    {
        return match ($provider) {
            'mail_smtp' => $request->validate([
                'credentials'               => ['required', 'array'],
                'credentials.host'          => ['required', 'string', 'max:255'],
                'credentials.port'          => ['required', 'integer', 'min:1', 'max:65535'],
                'credentials.username'      => ['required', 'string', 'max:255'],
                'credentials.password'      => ['required', 'string', 'max:255'],
                'credentials.encryption'    => ['nullable', 'string', Rule::in(['tls', 'ssl'])],
                'credentials.from_address'  => ['nullable', 'email', 'max:255'],
                'credentials.from_name'     => ['nullable', 'string', 'max:255'],
            ])['credentials'],

            'stripe' => $request->validate([
                'credentials'                 => ['required', 'array'],
                'credentials.publishable_key' => ['required', 'string', 'max:255'],
                'credentials.secret_key'      => ['required', 'string', 'max:255'],
            ])['credentials'],

            'paypal' => $request->validate([
                'credentials'               => ['required', 'array'],
                'credentials.client_id'     => ['required', 'string', 'max:255'],
                'credentials.client_secret' => ['required', 'string', 'max:255'],
                'credentials.mode'          => ['required', 'string', Rule::in(['sandbox', 'live'])],
            ])['credentials'],

            'wise' => $request->validate([
                'credentials'            => ['required', 'array'],
                'credentials.api_token'  => ['required', 'string', 'max:500'],
                'credentials.profile_id' => ['required', 'string', 'max:255'],
            ])['credentials'],

            'google_meet' => $request->validate([
                'credentials'                 => ['required', 'array'],
                'credentials.workspace_email' => ['required', 'email', 'max:255'],
                'credentials.api_key'         => ['nullable', 'string', 'max:255'],
                'credentials.calendar_id'     => ['nullable', 'string', 'max:255'],
            ])['credentials'],

            'microsoft_teams' => $request->validate([
                'credentials'                        => ['required', 'array'],
                'credentials.tenant_id'              => ['required', 'string', 'max:255'],
                'credentials.webhook_or_meeting_url' => ['required', 'string', 'max:1000'],
            ])['credentials'],

            'zoom' => $request->validate([
                'credentials'               => ['required', 'array'],
                'credentials.account_id'    => ['required', 'string', 'max:255'],
                'credentials.client_id'     => ['required', 'string', 'max:255'],
                'credentials.client_secret' => ['required', 'string', 'max:255'],
            ])['credentials'],

            'slack' => $request->validate([
                'credentials'             => ['required', 'array'],
                'credentials.webhook_url' => ['required', 'url', 'max:1000'],
                'credentials.channel'     => ['nullable', 'string', 'max:100'],
            ])['credentials'],

            'google_drive' => $request->validate([
                'credentials'           => ['required', 'array'],
                'credentials.folder_id' => ['required', 'string', 'max:255'],
                'credentials.api_key'   => ['required', 'string', 'max:500'],
            ])['credentials'],

            default => [],
        };
    }

    private function formatIntegration(AgencyIntegration $integration): array
    {
        $provider = $integration->provider;
        $creds = $integration->credentials;
        $oauthReady = $this->oauth->hasRealOAuthApp($provider);

        return [
            'provider'          => $provider,
            'status'            => $integration->status,
            'connected_at'      => $integration->connected_at,
            'connected_by'      => $integration->connected_by,
            'supports_oauth'    => $this->oauth->supportsOAuth($provider),
            'oauth_ready'       => $oauthReady,
            'auth_mode'         => $this->oauth->mode($provider),
            'setup_hint'        => $this->oauth->supportsOAuth($provider) && !$oauthReady
                ? $this->oauth->setupHint($provider)
                : null,
            'connection_method' => $integration->status === 'connected'
                ? (data_get($creds, 'auth_type') === 'oauth' ? 'oauth' : 'manual')
                : null,
        ];
    }

    private function disconnectedPlaceholder(string $provider): array
    {
        $oauthReady = $this->oauth->hasRealOAuthApp($provider);

        return [
            'provider'          => $provider,
            'status'            => 'disconnected',
            'connected_at'      => null,
            'connected_by'      => null,
            'supports_oauth'    => $this->oauth->supportsOAuth($provider),
            'oauth_ready'       => $oauthReady,
            'auth_mode'         => $this->oauth->mode($provider),
            'setup_hint'        => $this->oauth->supportsOAuth($provider) && !$oauthReady
                ? $this->oauth->setupHint($provider)
                : null,
            'connection_method' => null,
        ];
    }
}
