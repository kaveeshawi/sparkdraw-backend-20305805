<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\StoreCardPaymentRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Traits\ApiResponse;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Services\PayPalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PayPalService $payPalService) {}

    // GET /api/v1/invoices
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Invoice::with(['client:id,company_name', 'project:id,name'])
            ->orderByDesc('created_at');

        if ($user->role === 'client') {
            $client = Client::where('contact_user_id', $user->id)->first();
            if (!$client) {
                return $this->success([]);
            }
            $query->where('client_id', $client->id)
                ->whereIn('status', ['sent', 'paid', 'overdue']);
        }

        if ($status = $request->query('status')) {
            if ($status === 'overdue') {
                $query->where('status', 'sent')->where('due_date', '<', now()->startOfDay());
            } else {
                $query->where('status', $status);
            }
        }

        return $this->success(InvoiceResource::collection($query->get()));
    }

    // POST /api/v1/invoices
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id'  => ['required', 'integer', 'exists:clients,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'due_date'   => ['nullable', 'date'],
            'notes'      => ['nullable', 'string', 'max:2000'],
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.description' => ['required', 'string', 'max:500'],
            'line_items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
            'line_items.*.rate'        => ['required', 'numeric', 'min:0'],
        ]);

        $user = $request->user();

        $lineItems = collect($validated['line_items'])->map(function ($item) {
            $qty    = (float) $item['quantity'];
            $rate   = (float) $item['rate'];
            $amount = round($qty * $rate, 2);

            return [
                'description' => $item['description'],
                'quantity'    => $qty,
                'rate'        => $rate,
                'amount'      => $amount,
            ];
        })->all();

        $total = round(collect($lineItems)->sum('amount'), 2);

        $invoice = DB::transaction(function () use ($validated, $user, $lineItems, $total) {
            $invoice = Invoice::create([
                'agency_id'      => $user->agency_id,
                'client_id'      => $validated['client_id'],
                'project_id'     => $validated['project_id'],
                'invoice_number' => Invoice::generateInvoiceNumber($user->agency_id),
                'amount'         => $total,
                'line_items'     => $lineItems,
                'status'         => 'draft',
                'due_date'       => $validated['due_date'] ?? null,
                'notes'          => $validated['notes'] ?? null,
            ]);

            ProjectEvent::log($user->agency_id, $validated['project_id'], 'invoice_created', [
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'amount'         => $total,
                'client_id'      => $validated['client_id'],
            ]);

            return $invoice;
        });

        $invoice->load(['client.contactUser:id,name,email', 'project:id,name']);

        return $this->created(new InvoiceResource($invoice), 'Invoice created successfully.');
    }

    // GET /api/v1/invoices/{invoice}
    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        if (!$this->canAccessInvoice($request, $invoice)) {
            return $this->forbidden('You do not have access to this invoice.');
        }

        $invoice->load(['client.contactUser:id,name,email', 'project:id,name']);

        return $this->success(new InvoiceResource($invoice));
    }

    // PUT /api/v1/invoices/{invoice} — admin only
    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'status'     => ['sometimes', 'in:draft,sent,paid,overdue'],
            'due_date'   => ['sometimes', 'nullable', 'date'],
            'notes'      => ['sometimes', 'nullable', 'string', 'max:2000'],
            'line_items' => ['sometimes', 'array', 'min:1'],
            'line_items.*.description' => ['required_with:line_items', 'string', 'max:500'],
            'line_items.*.quantity'    => ['required_with:line_items', 'numeric', 'min:0.01'],
            'line_items.*.rate'        => ['required_with:line_items', 'numeric', 'min:0'],
        ]);

        if (isset($validated['line_items'])) {
            $lineItems = collect($validated['line_items'])->map(function ($item) {
                $qty    = (float) $item['quantity'];
                $rate   = (float) $item['rate'];
                $amount = round($qty * $rate, 2);

                return [
                    'description' => $item['description'],
                    'quantity'    => $qty,
                    'rate'        => $rate,
                    'amount'      => $amount,
                ];
            })->all();

            $validated['line_items'] = $lineItems;
            $validated['amount']    = round(collect($lineItems)->sum('amount'), 2);
        }

        $invoice->update($validated);
        $invoice->load(['client.contactUser:id,name,email', 'project:id,name']);

        return $this->success(new InvoiceResource($invoice), 'Invoice updated.');
    }

    // PATCH /api/v1/invoices/{invoice}/send
    public function send(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status !== 'draft') {
            return $this->error('Only draft invoices can be sent.', [], 422);
        }

        $invoice->update(['status' => 'sent']);

        ProjectEvent::log($request->user()->agency_id, $invoice->project_id, 'invoice_sent', [
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'client_id'      => $invoice->client_id,
            'amount'         => (float) $invoice->amount,
        ]);

        $invoice->load(['client.contactUser:id,name,email', 'project:id,name']);

        return $this->success(new InvoiceResource($invoice), 'Invoice marked as sent.');
    }

    // GET /api/v1/invoices/revenue — admin only
    public function revenue(Request $request): JsonResponse
    {
        $agencyId = $request->user()->agency_id;

        $thisMonthStart = now()->startOfMonth();
        $lastMonthStart = now()->subMonth()->startOfMonth();
        $lastMonthEnd   = now()->subMonth()->endOfMonth();

        $thisMonth = (float) Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $thisMonthStart)
            ->sum('amount');

        $lastMonth = (float) Invoice::where('status', 'paid')
            ->whereBetween('paid_at', [$lastMonthStart, $lastMonthEnd])
            ->sum('amount');

        $changePct = $lastMonth > 0
            ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
            : ($thisMonth > 0 ? 100.0 : 0.0);

        $paidCount = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $thisMonthStart)
            ->count();

        return $this->success([
            'this_month'  => round($thisMonth, 2),
            'last_month'  => round($lastMonth, 2),
            'change_pct'  => $changePct,
            'paid_count'  => $paidCount,
        ]);
    }

    // POST /api/v1/invoices/{invoice}/pay
    public function createPayment(Request $request, Invoice $invoice): JsonResponse
    {
        if (!$this->canAccessInvoice($request, $invoice)) {
            return $this->forbidden('You do not have access to this invoice.');
        }

        if (!in_array($invoice->displayStatus(), ['sent', 'overdue'], true)) {
            return $this->error('This invoice is not available for payment.', [], 422);
        }

        $result = $this->payPalService->createOrder($invoice);

        if (empty($result['success'])) {
            return $this->error($result['message'] ?? 'PayPal unavailable', [], 503);
        }

        $invoice->update(['paypal_order_id' => $result['order_id']]);

        return $this->success([
            'order_id'      => $result['order_id'],
            'approval_url'  => $result['approval_url'],
            'sandbox_mode'  => config('paypal.mode') === 'sandbox',
        ]);
    }

    // POST /api/v1/invoices/{invoice}/pay-card — sandbox card checkout (no PayPal redirect)
    public function processCardPayment(StoreCardPaymentRequest $request, Invoice $invoice): JsonResponse
    {
        if (!$this->canAccessInvoice($request, $invoice)) {
            return $this->forbidden('You do not have access to this invoice.');
        }

        if (!in_array($invoice->displayStatus(), ['sent', 'overdue'], true)) {
            return $this->error('This invoice is not available for payment.', [], 422);
        }

        $transactionId = 'SANDBOX-' . strtoupper(Str::random(12));

        $invoice->update([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);

        ProjectEvent::log($invoice->agency_id, $invoice->project_id, 'invoice_paid', [
            'amount'          => (float) $invoice->amount,
            'transaction_id'  => $transactionId,
            'payment_method'  => 'card_sandbox',
            'invoice_id'      => $invoice->id,
            'invoice_number'  => $invoice->invoice_number,
        ]);

        return $this->success([
            'invoice_id'       => $invoice->id,
            'invoice_number'   => $invoice->invoice_number,
            'status'           => 'paid',
            'transaction_id'   => $transactionId,
            'payment_method'   => 'card',
            'message'          => 'Payment successful.',
        ]);
    }

    // GET /api/v1/invoices/{invoice}/payment-success — public PayPal redirect
    public function capturePayment(Request $request, Invoice $invoice): JsonResponse
    {
        $orderId = $request->query('token') ?? $invoice->paypal_order_id;

        if (!$orderId) {
            return $this->error('Missing PayPal order token.', [], 422);
        }

        $result = $this->payPalService->captureOrder($orderId);

        if (empty($result['success']) || ($result['status'] ?? '') !== 'COMPLETED') {
            return $this->error($result['message'] ?? 'Payment capture failed.', [], 422);
        }

        $invoice->update([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);

        ProjectEvent::log($invoice->agency_id, $invoice->project_id, 'invoice_paid', [
            'amount'          => (float) $invoice->amount,
            'transaction_id'  => $result['transaction_id'],
            'paypal_order_id' => $orderId,
            'invoice_id'      => $invoice->id,
            'invoice_number'  => $invoice->invoice_number,
        ]);

        return $this->success([
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status'         => 'paid',
            'transaction_id' => $result['transaction_id'],
            'message'        => 'Payment successful.',
        ]);
    }

    // GET /api/v1/invoices/{invoice}/payment-cancel — public PayPal redirect
    public function paymentCancelled(Request $request, Invoice $invoice): JsonResponse
    {
        ProjectEvent::log($invoice->agency_id, $invoice->project_id, 'invoice_payment_cancelled', [
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'paypal_order_id'=> $invoice->paypal_order_id,
        ]);

        return $this->success([
            'invoice_id' => $invoice->id,
            'message'    => 'Payment cancelled.',
        ]);
    }

    private function canAccessInvoice(Request $request, Invoice $invoice): bool
    {
        $user = $request->user();

        if (in_array($user->role, ['admin', 'pm'], true)) {
            return $invoice->agency_id === $user->agency_id;
        }

        if ($user->role === 'client') {
            $client = Client::where('contact_user_id', $user->id)->first();

            return $client
                && $invoice->client_id === $client->id
                && $invoice->agency_id === $user->agency_id;
        }

        return false;
    }
}
