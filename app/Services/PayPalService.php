<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayPalService
{
    public function __construct(
        private readonly AgencyIntegrationResolver $integrations,
    ) {}

    /**
     * @return array{client_id: string, client_secret: string, mode: string, base_url: string}|null
     */
    public function resolveCredentials(?int $agencyId = null): ?array
    {
        $clientId     = '';
        $clientSecret = '';
        $mode         = config('paypal.mode', 'sandbox');

        if ($agencyId) {
            $creds = $this->integrations->credentials($agencyId, 'paypal');
            if ($creds) {
                $clientId     = (string) ($creds['client_id'] ?? '');
                $clientSecret = (string) ($creds['client_secret'] ?? '');
                $mode         = (string) ($creds['mode'] ?? 'sandbox');
            }
        }

        if ($clientId === '' || $clientSecret === '') {
            $clientId     = (string) config('paypal.client_id', '');
            $clientSecret = (string) config('paypal.client_secret', '');
            $mode         = (string) config('paypal.mode', 'sandbox');
        }

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $baseUrl = $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        return [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'mode'          => $mode,
            'base_url'      => $baseUrl,
        ];
    }

    public function getAccessToken(?int $agencyId = null): ?string
    {
        $creds = $this->resolveCredentials($agencyId);

        if (!$creds) {
            Log::warning('PayPal credentials not configured', ['agency_id' => $agencyId]);

            return null;
        }

        try {
            $response = Http::withBasicAuth($creds['client_id'], $creds['client_secret'])
                ->asForm()
                ->post("{$creds['base_url']}/v1/oauth2/token", [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->successful()) {
                return $response->json('access_token');
            }

            Log::warning('PayPal OAuth failed', ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('PayPal OAuth unreachable', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function createOrder(Invoice $invoice): array
    {
        $agencyId = $invoice->agency_id;
        $token    = $this->getAccessToken($agencyId);
        $creds    = $this->resolveCredentials($agencyId);

        if (!$token || !$creds) {
            return ['success' => false, 'message' => 'PayPal service unavailable'];
        }

        $invoice->loadMissing('agency');

        $returnUrl = config('paypal.return_url') . "/{$invoice->id}/success";
        $cancelUrl = config('paypal.cancel_url') . "/{$invoice->id}/cancel";

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->post("{$creds['base_url']}/v2/checkout/orders", [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'reference_id' => (string) $invoice->id,
                        'description'  => $invoice->invoice_number ?? "Invoice #{$invoice->id}",
                        'amount' => [
                            'currency_code' => strtoupper($invoice->agency?->currency ?? 'USD'),
                            'value'         => number_format((float) $invoice->amount, 2, '.', ''),
                        ],
                    ]],
                    'application_context' => [
                        'return_url'  => $returnUrl,
                        'cancel_url'  => $cancelUrl,
                        'brand_name'  => 'Sparkdraw (Sandbox)',
                        'user_action' => 'PAY_NOW',
                    ],
                ]);

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Failed to create PayPal order'];
            }

            $data        = $response->json();
            $approvalUrl = collect($data['links'] ?? [])
                ->firstWhere('rel', 'approve')['href'] ?? null;

            if (!$approvalUrl) {
                return ['success' => false, 'message' => 'PayPal approval URL not returned'];
            }

            return [
                'success'      => true,
                'order_id'     => $data['id'],
                'approval_url' => $approvalUrl,
                'mode'         => $creds['mode'],
            ];
        } catch (\Throwable $e) {
            Log::warning('PayPal createOrder failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'PayPal service unavailable'];
        }
    }

    public function captureOrder(string $orderId, ?int $agencyId = null): array
    {
        $token = $this->getAccessToken($agencyId);
        $creds = $this->resolveCredentials($agencyId);

        if (!$token || !$creds) {
            return ['success' => false, 'message' => 'PayPal service unavailable'];
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->post("{$creds['base_url']}/v2/checkout/orders/{$orderId}/capture");

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Failed to capture PayPal payment'];
            }

            $data          = $response->json();
            $capture       = $data['purchase_units'][0]['payments']['captures'][0] ?? [];
            $transactionId = $capture['id'] ?? null;
            $status        = $data['status'] ?? 'UNKNOWN';

            return [
                'success'        => true,
                'status'         => $status,
                'transaction_id' => $transactionId,
            ];
        } catch (\Throwable $e) {
            Log::warning('PayPal captureOrder failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'PayPal service unavailable'];
        }
    }
}
