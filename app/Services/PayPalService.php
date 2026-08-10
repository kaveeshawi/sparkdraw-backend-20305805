<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayPalService
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;

    public function __construct()
    {
        $this->baseUrl      = config('paypal.base_url');
        $this->clientId     = config('paypal.client_id', '');
        $this->clientSecret = config('paypal.client_secret', '');
    }

    public function getAccessToken(): ?string
    {
        if (empty($this->clientId) || empty($this->clientSecret)) {
            Log::warning('PayPal credentials not configured');

            return null;
        }

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post("{$this->baseUrl}/v1/oauth2/token", [
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
        $token = $this->getAccessToken();

        if (!$token) {
            return ['success' => false, 'message' => 'PayPal service unavailable'];
        }

        $returnUrl = config('paypal.return_url') . "/{$invoice->id}/success";
        $cancelUrl = config('paypal.cancel_url') . "/{$invoice->id}/cancel";

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->post("{$this->baseUrl}/v2/checkout/orders", [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'reference_id' => (string) $invoice->id,
                        'description'  => $invoice->invoice_number ?? "Invoice #{$invoice->id}",
                        'amount' => [
                            'currency_code' => 'USD',
                            'value'         => number_format((float) $invoice->amount, 2, '.', ''),
                        ],
                    ]],
                    'application_context' => [
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                        'brand_name' => 'Sparkdraw (Sandbox)',
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
                'success'       => true,
                'order_id'      => $data['id'],
                'approval_url'  => $approvalUrl,
            ];
        } catch (\Throwable $e) {
            Log::warning('PayPal createOrder failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'PayPal service unavailable'];
        }
    }

    public function captureOrder(string $orderId): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return ['success' => false, 'message' => 'PayPal service unavailable'];
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->post("{$this->baseUrl}/v2/checkout/orders/{$orderId}/capture");

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Failed to capture PayPal payment'];
            }

            $data            = $response->json();
            $capture         = $data['purchase_units'][0]['payments']['captures'][0] ?? [];
            $transactionId = $capture['id'] ?? null;
            $status          = $data['status'] ?? 'UNKNOWN';

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
