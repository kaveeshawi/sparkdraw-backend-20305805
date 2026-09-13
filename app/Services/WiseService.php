<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WiseService
{
    public function __construct(
        private readonly AgencyIntegrationResolver $integrations,
    ) {}

    public function resolveCredentials(int $agencyId): ?array
    {
        $creds = $this->integrations->credentials($agencyId, 'wise');

        if (!$creds) {
            return null;
        }

        $token     = trim((string) ($creds['api_token'] ?? ''));
        $profileId = trim((string) ($creds['profile_id'] ?? ''));

        if ($token === '' || $profileId === '') {
            return null;
        }

        return [
            'api_token'  => $token,
            'profile_id' => $profileId,
        ];
    }

    /**
     * Record a Wise / bank-transfer style payment for an invoice.
     * Attempts a lightweight credential check against Wise sandbox when possible;
     * otherwise records a demo transfer reference (academic V1).
     */
    public function recordPayment(Invoice $invoice): array
    {
        $creds = $this->resolveCredentials($invoice->agency_id);

        if (!$creds) {
            return [
                'success' => false,
                'message' => 'Wise is not connected for this agency.',
            ];
        }

        $verified = false;

        try {
            $response = Http::withToken($creds['api_token'])
                ->acceptJson()
                ->timeout(8)
                ->get("https://api.sandbox.transferwise.tech/v1/profiles/{$creds['profile_id']}");

            $verified = $response->successful();

            if (!$verified) {
                Log::info('Wise profile check non-success; continuing with demo transfer', [
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::info('Wise API unreachable; recording demo transfer', [
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'success'        => true,
            'transaction_id' => 'WISE-' . strtoupper(Str::random(12)),
            'payment_method' => 'wise',
            'profile_verified' => $verified,
            'message'        => 'Wise payment recorded.',
        ];
    }
}
