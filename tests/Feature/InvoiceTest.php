<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyIntegration;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Services\PayPalService;
use App\Services\StripeService;
use App\Services\WiseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(string $slug = 'inv-agency'): Agency
    {
        return Agency::create([
            'name'         => "Agency {$slug}",
            'domain_slug'  => $slug,
            'brand_colors' => ['primary' => '#802AEE', 'light' => '#f3e8ff'],
        ]);
    }

    private function makeUser(Agency $agency, string $role = 'admin'): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'agency_id' => $agency->id,
            'role'      => $role,
            'name'      => ucfirst($role) . " {$n}",
            'email'     => "{$role}{$n}@inv-test.com",
            'password'  => Hash::make('password'),
        ]);
    }

    private function makeClientWithUser(Agency $agency): array
    {
        $contact = $this->makeUser($agency, 'client');

        $client = Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Invoice Client Co',
            'contact_user_id' => $contact->id,
        ]);

        return [$client, $contact];
    }

    private function makeProject(Agency $agency, Client $client): Project
    {
        return Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client->id,
            'name'       => 'Invoice Project',
            'type'       => 'web_design',
            'status'     => 'active',
            'start_date' => '2026-07-01',
            'end_date'   => '2026-09-30',
        ]);
    }

    private function lineItemsPayload(): array
    {
        return [
            ['description' => 'Design work', 'quantity' => 10, 'rate' => 100],
            ['description' => 'Development', 'quantity' => 5, 'rate' => 150],
        ];
    }

    public function test_admin_can_create_invoice(): void
    {
        $agency  = $this->makeAgency();
        $admin   = $this->makeUser($agency, 'admin');
        [$client] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        $response = $this->actingAs($admin)->postJson('/api/v1/invoices', [
            'client_id'  => $client->id,
            'project_id' => $project->id,
            'due_date'   => '2026-08-01',
            'notes'      => 'Net 30',
            'line_items' => $this->lineItemsPayload(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 1750);

        $this->assertDatabaseHas('invoices', [
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'amount'    => 1750,
            'status'    => 'draft',
        ]);

        $this->assertDatabaseHas('project_events', [
            'event_type' => 'invoice_created',
        ]);
    }

    public function test_invoice_number_auto_generates_correctly(): void
    {
        $agency  = $this->makeAgency('inv-num');
        $admin   = $this->makeUser($agency, 'admin');
        [$client] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        $this->actingAs($admin)->postJson('/api/v1/invoices', [
            'client_id'  => $client->id,
            'project_id' => $project->id,
            'line_items' => $this->lineItemsPayload(),
        ]);

        $invoice = Invoice::first();
        $year    = now()->year;

        $this->assertEquals("INV-{$year}-001", $invoice->invoice_number);
    }

    public function test_revenue_endpoint_returns_correct_monthly_total(): void
    {
        $agency  = $this->makeAgency('inv-rev');
        $admin   = $this->makeUser($agency, 'admin');
        [$client] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        Invoice::create([
            'agency_id'      => $agency->id,
            'client_id'      => $client->id,
            'project_id'     => $project->id,
            'invoice_number' => 'INV-2026-001',
            'amount'         => 1000,
            'line_items'     => [],
            'status'         => 'paid',
            'paid_at'        => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/invoices/revenue');

        $response->assertOk()
            ->assertJsonPath('data.this_month', 1000)
            ->assertJsonPath('data.paid_count', 1);
    }

    public function test_paypal_pay_endpoint_returns_approval_url(): void
    {
        $this->mock(PayPalService::class, function ($mock) {
            $mock->shouldReceive('resolveCredentials')
                ->andReturn([
                    'client_id'     => 'id',
                    'client_secret' => 'secret',
                    'mode'          => 'sandbox',
                    'base_url'      => 'https://api-m.sandbox.paypal.com',
                ]);
            $mock->shouldReceive('createOrder')
                ->once()
                ->andReturn([
                    'success'      => true,
                    'order_id'     => 'ORDER-123',
                    'approval_url' => 'https://sandbox.paypal.com/checkout?token=ORDER-123',
                    'mode'         => 'sandbox',
                ]);
        });

        $agency  = $this->makeAgency('inv-pay');
        $admin   = $this->makeUser($agency, 'admin');
        [$client] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        $invoice = Invoice::create([
            'agency_id'      => $agency->id,
            'client_id'      => $client->id,
            'project_id'     => $project->id,
            'invoice_number' => 'INV-2026-002',
            'amount'         => 500,
            'line_items'     => [],
            'status'         => 'sent',
            'due_date'       => now()->addDays(14),
        ]);

        $response = $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/pay");

        $response->assertOk()
            ->assertJsonPath('data.approval_url', 'https://sandbox.paypal.com/checkout?token=ORDER-123');
    }

    public function test_payment_success_marks_invoice_paid(): void
    {
        $this->mock(PayPalService::class, function ($mock) {
            $mock->shouldReceive('captureOrder')
                ->once()
                ->andReturn([
                    'success'        => true,
                    'status'         => 'COMPLETED',
                    'transaction_id' => 'TXN-789',
                ]);
        });

        $agency  = $this->makeAgency('inv-cap');
        [$client] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        $invoice = Invoice::create([
            'agency_id'       => $agency->id,
            'client_id'       => $client->id,
            'project_id'      => $project->id,
            'invoice_number'  => 'INV-2026-003',
            'amount'          => 750,
            'line_items'      => [],
            'status'          => 'sent',
            'paypal_order_id' => 'ORDER-456',
        ]);

        $response = $this->getJson("/api/v1/invoices/{$invoice->id}/payment-success?token=ORDER-456");

        $response->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('invoices', [
            'id'     => $invoice->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('project_events', [
            'event_type' => 'invoice_paid',
        ]);
    }

    public function test_card_payment_requires_stripe_and_marks_invoice_paid(): void
    {
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('chargeCard')
                ->once()
                ->andReturn([
                    'success'        => true,
                    'transaction_id' => 'pi_test_123',
                    'payment_method' => 'stripe',
                    'test_mode'      => true,
                ]);
        });

        $agency  = $this->makeAgency('inv-card');
        $admin   = $this->makeUser($agency, 'admin');
        [$client] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        AgencyIntegration::create([
            'agency_id'    => $agency->id,
            'provider'     => 'stripe',
            'status'       => 'connected',
            'credentials'  => [
                'publishable_key' => 'pk_test_x',
                'secret_key'      => 'sk_test_x',
            ],
            'connected_by' => $admin->id,
            'connected_at' => now(),
        ]);

        $invoice = Invoice::create([
            'agency_id'      => $agency->id,
            'client_id'      => $client->id,
            'project_id'     => $project->id,
            'invoice_number' => 'INV-2026-010',
            'amount'         => 246,
            'line_items'     => [],
            'status'         => 'sent',
            'due_date'       => now()->addDays(14),
        ]);

        $response = $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/pay-card", [
            'card_name'   => 'Test User',
            'card_number' => '4111111111111111',
            'expiry'      => '12/28',
            'cvv'         => '123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_method', 'card');

        $this->assertDatabaseHas('invoices', [
            'id'     => $invoice->id,
            'status' => 'paid',
        ]);
    }

    public function test_card_payment_fails_when_stripe_not_connected(): void
    {
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('chargeCard')
                ->once()
                ->andReturn([
                    'success' => false,
                    'message' => 'Stripe is not connected for this agency.',
                ]);
        });

        $agency  = $this->makeAgency('inv-card-off');
        $admin   = $this->makeUser($agency, 'admin');
        [$client] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        $invoice = Invoice::create([
            'agency_id'      => $agency->id,
            'client_id'      => $client->id,
            'project_id'     => $project->id,
            'invoice_number' => 'INV-2026-011',
            'amount'         => 100,
            'line_items'     => [],
            'status'         => 'sent',
        ]);

        $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/pay-card", [
            'card_name'   => 'Test User',
            'card_number' => '4111111111111111',
            'expiry'      => '12/28',
            'cvv'         => '123',
        ])->assertStatus(503);
    }

    public function test_wise_payment_marks_invoice_paid(): void
    {
        $this->mock(WiseService::class, function ($mock) {
            $mock->shouldReceive('recordPayment')
                ->once()
                ->andReturn([
                    'success'        => true,
                    'transaction_id' => 'WISE-ABC',
                    'payment_method' => 'wise',
                    'message'        => 'Wise payment recorded.',
                ]);
        });

        $agency  = $this->makeAgency('inv-wise');
        $admin   = $this->makeUser($agency, 'admin');
        [$client] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        $invoice = Invoice::create([
            'agency_id'      => $agency->id,
            'client_id'      => $client->id,
            'project_id'     => $project->id,
            'invoice_number' => 'INV-2026-012',
            'amount'         => 300,
            'line_items'     => [],
            'status'         => 'sent',
        ]);

        $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/pay-wise")
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'wise')
            ->assertJsonPath('data.status', 'paid');
    }

    public function test_invoice_show_includes_available_payment_methods_from_integrations(): void
    {
        $agency  = $this->makeAgency('inv-methods');
        $admin   = $this->makeUser($agency, 'admin');
        [$client] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        AgencyIntegration::create([
            'agency_id'    => $agency->id,
            'provider'     => 'stripe',
            'status'       => 'connected',
            'credentials'  => [
                'publishable_key' => 'pk_test_x',
                'secret_key'      => 'sk_test_x',
            ],
            'connected_by' => $admin->id,
            'connected_at' => now(),
        ]);

        AgencyIntegration::create([
            'agency_id'    => $agency->id,
            'provider'     => 'wise',
            'status'       => 'connected',
            'credentials'  => [
                'api_token'  => 'token',
                'profile_id' => '1',
            ],
            'connected_by' => $admin->id,
            'connected_at' => now(),
        ]);

        $invoice = Invoice::create([
            'agency_id'      => $agency->id,
            'client_id'      => $client->id,
            'project_id'     => $project->id,
            'invoice_number' => 'INV-2026-013',
            'amount'         => 50,
            'line_items'     => [],
            'status'         => 'sent',
        ]);

        $response = $this->actingAs($admin)->getJson("/api/v1/invoices/{$invoice->id}");
        $response->assertOk();

        $methods = $response->json('data.available_payment_methods');
        $this->assertContains('stripe', $methods);
        $this->assertContains('wise', $methods);
    }

    public function test_client_cannot_create_invoice(): void
    {
        $agency = $this->makeAgency('inv-cli');
        [$client, $clientUser] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        $response = $this->actingAs($clientUser)->postJson('/api/v1/invoices', [
            'client_id'  => $client->id,
            'project_id' => $project->id,
            'line_items' => $this->lineItemsPayload(),
        ]);

        $response->assertStatus(403);
    }

    public function test_create_invoice_stores_template_snapshot(): void
    {
        $agency  = $this->makeAgency('inv-tpl');
        $admin   = $this->makeUser($agency, 'admin');
        [$client] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        $response = $this->actingAs($admin)->postJson('/api/v1/invoices', [
            'client_id'  => $client->id,
            'project_id' => $project->id,
            'line_items' => $this->lineItemsPayload(),
            'template_id' => 'invoice-classic',
            'template_snapshot' => [
                'layout' => 'invoice-classic',
                'accent' => '#0023D7',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.template_id', 'invoice-classic')
            ->assertJsonPath('data.template_snapshot.layout', 'invoice-classic');

        $this->assertDatabaseHas('invoices', [
            'agency_id'   => $agency->id,
            'template_id' => 'invoice-classic',
        ]);
    }

    public function test_revenue_includes_aging_and_outstanding(): void
    {
        $agency  = $this->makeAgency('inv-age');
        $admin   = $this->makeUser($agency, 'admin');
        [$client] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        Invoice::create([
            'agency_id'      => $agency->id,
            'client_id'      => $client->id,
            'project_id'     => $project->id,
            'invoice_number' => 'INV-AGE-001',
            'amount'         => 500,
            'line_items'     => [],
            'status'         => 'sent',
            'due_date'       => now()->subDays(10)->toDateString(),
        ]);

        Invoice::create([
            'agency_id'      => $agency->id,
            'client_id'      => $client->id,
            'project_id'     => $project->id,
            'invoice_number' => 'INV-AGE-002',
            'amount'         => 200,
            'line_items'     => [],
            'status'         => 'draft',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/invoices/revenue');

        $response->assertOk()
            ->assertJsonPath('data.outstanding', 500)
            ->assertJsonPath('data.overdue_total', 500)
            ->assertJsonPath('data.draft_count', 1);

        $aging = collect($response->json('data.aging'));
        $this->assertEquals(1, $aging->firstWhere('bucket', '0-30')['count']);
    }

    public function test_invoice_reminder_returns_draft_for_overdue(): void
    {
        $agency  = $this->makeAgency('inv-ai');
        $admin   = $this->makeUser($agency, 'admin');
        [$client] = $this->makeClientWithUser($agency);
        $project = $this->makeProject($agency, $client);

        $invoice = Invoice::create([
            'agency_id'      => $agency->id,
            'client_id'      => $client->id,
            'project_id'     => $project->id,
            'invoice_number' => 'INV-AI-001',
            'amount'         => 750,
            'line_items'     => [],
            'status'         => 'sent',
            'due_date'       => now()->subDays(5)->toDateString(),
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/ai/invoice-reminder', [
            'invoice_id' => $invoice->id,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['subject', 'body']]);
        $this->assertStringContainsString('INV-AI-001', $response->json('data.subject'));
    }

    public function test_revenue_is_tenant_isolated(): void
    {
        $agencyA = $this->makeAgency('inv-ta');
        $adminA  = $this->makeUser($agencyA, 'admin');
        [$clientA] = $this->makeClientWithUser($agencyA);
        $projectA = $this->makeProject($agencyA, $clientA);
        Invoice::create([
            'agency_id' => $agencyA->id,
            'client_id' => $clientA->id,
            'project_id' => $projectA->id,
            'invoice_number' => 'INV-TA-001',
            'amount' => 999,
            'line_items' => [],
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $agencyB = $this->makeAgency('inv-tb');
        $adminB  = $this->makeUser($agencyB, 'admin');

        $response = $this->actingAs($adminB)->getJson('/api/v1/invoices/revenue');
        $response->assertOk()
            ->assertJsonPath('data.this_month', 0)
            ->assertJsonPath('data.outstanding', 0);
    }
}
