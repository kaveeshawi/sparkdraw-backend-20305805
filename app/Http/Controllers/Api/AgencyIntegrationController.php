<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\AgencyIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgencyIntegrationController extends Controller
{
    use ApiResponse;

    private const PROVIDERS = ['mail_smtp', 'google_meet', 'microsoft_teams', 'stripe'];

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
                'credentials'                    => ['required', 'array'],
                'credentials.publishable_key'    => ['required', 'string', 'max:255'],
                'credentials.secret_key'       => ['required', 'string', 'max:255'],
            ])['credentials'],
            'google_meet', 'microsoft_teams' => ['connected' => true],
            default => [],
        };
    }

    private function formatIntegration(AgencyIntegration $integration): array
    {
        return [
            'provider'     => $integration->provider,
            'status'       => $integration->status,
            'connected_at' => $integration->connected_at,
            'connected_by' => $integration->connected_by,
        ];
    }

    private function disconnectedPlaceholder(string $provider): array
    {
        return [
            'provider'     => $provider,
            'status'       => 'disconnected',
            'connected_at' => null,
            'connected_by' => null,
        ];
    }
}
