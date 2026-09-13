<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AiCreditUsage;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\AiCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiCreditTest extends TestCase
{
    use RefreshDatabase;

    private function seedUsers(): array
    {
        $agency = Agency::create([
            'name'         => 'Credits Agency',
            'domain_slug'  => 'credits-agency-01',
            'brand_colors' => ['primary' => '#802AEE'],
        ]);

        $admin = User::create([
            'agency_id' => $agency->id,
            'role'      => 'admin',
            'name'      => 'Admin',
            'email'     => 'admin-credits@test.com',
            'password'  => Hash::make('password'),
        ]);

        $member = User::create([
            'agency_id' => $agency->id,
            'role'      => 'member',
            'name'      => 'Member',
            'email'     => 'member-credits@test.com',
            'password'  => Hash::make('password'),
        ]);

        $clientUser = User::create([
            'agency_id' => $agency->id,
            'role'      => 'client',
            'name'      => 'Client',
            'email'     => 'client-credits@test.com',
            'password'  => Hash::make('password'),
        ]);

        $client = Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Credits Co',
            'contact_user_id' => $clientUser->id,
        ]);

        $project = Project::create([
            'agency_id'       => $agency->id,
            'client_id'       => $client->id,
            'name'            => 'Credits Project',
            'type'            => 'web',
            'status'          => 'active',
            'estimated_hours' => 40,
            'start_date'      => now()->toDateString(),
            'end_date'        => now()->addMonth()->toDateString(),
        ]);

        return compact('agency', 'admin', 'member', 'project');
    }

    public function test_admin_gets_higher_monthly_allowance_than_member(): void
    {
        ['admin' => $admin, 'member' => $member] = $this->seedUsers();
        $service = app(AiCreditService::class);

        $this->assertGreaterThan(
            $service->allowanceFor($member),
            $service->allowanceFor($admin)
        );

        $this->assertSame(1000, $service->allowanceFor($member));
        $this->assertSame(1500, $service->allowanceFor($admin));

        // PM shares the same team pool as members
        $pm = User::create([
            'agency_id' => $member->agency_id,
            'role'      => 'pm',
            'name'      => 'PM',
            'email'     => 'pm-credits@test.com',
            'password'  => Hash::make('password'),
        ]);
        $this->assertSame($service->allowanceFor($member), $service->allowanceFor($pm));
    }

    public function test_credits_summary_endpoint_returns_balance_and_feature_costs(): void
    {
        ['admin' => $admin] = $this->seedUsers();

        $this->actingAs($admin)
            ->getJson('/api/v1/ai/credits')
            ->assertOk()
            ->assertJsonPath('data.plan_name', 'Agency Starter')
            ->assertJsonPath('data.allowance', 1500)
            ->assertJsonPath('data.used', 0)
            ->assertJsonPath('data.remaining', 1500)
            ->assertJsonStructure([
                'data' => [
                    'period' => ['start', 'end'],
                    'features' => [['feature', 'label', 'cost']],
                    'allowances_by_role' => ['admin', 'pm', 'member'],
                ],
            ]);
    }

    public function test_successful_ai_call_deducts_credits(): void
    {
        ['admin' => $admin, 'project' => $project] = $this->seedUsers();

        Http::fake([
            'localhost:8001/analyze-feedback' => Http::response([
                'success' => true,
                'data'    => [
                    'title'         => 'UI polish',
                    'category'      => 'ui',
                    'priority'      => 'medium',
                    'subtasks'      => ['Spacing'],
                    'assigned_role' => 'designer',
                ],
            ], 200),
        ]);

        $this->actingAs($admin)->postJson('/api/v1/ai/analyze-feedback', [
            'feedback_text' => 'Please make the homepage feel more premium overall',
            'project_type'  => 'web',
            'project_id'    => $project->id,
        ])->assertOk();

        $this->assertDatabaseHas('ai_credit_usages', [
            'user_id'  => $admin->id,
            'feature'  => 'analyze_feedback',
            'credits'  => 2,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/ai/credits')
            ->assertOk()
            ->assertJsonPath('data.used', 2)
            ->assertJsonPath('data.remaining', 1498);
    }

    public function test_insufficient_credits_returns_402(): void
    {
        ['member' => $member, 'project' => $project] = $this->seedUsers();

        // Burn member's monthly pool (1000)
        AiCreditUsage::create([
            'agency_id' => $member->agency_id,
            'user_id'   => $member->id,
            'feature'   => 'brief_generator',
            'credits'   => 1000,
        ]);

        Http::fake([
            'localhost:8001/analyze-feedback' => Http::response([
                'success' => true,
                'data'    => ['title' => 'X', 'category' => 'ui', 'priority' => 'low', 'subtasks' => []],
            ], 200),
        ]);

        $this->actingAs($member)->postJson('/api/v1/ai/analyze-feedback', [
            'feedback_text' => 'Please make the homepage feel more premium overall',
            'project_type'  => 'web',
            'project_id'    => $project->id,
        ])->assertStatus(402)
            ->assertJsonPath('success', false);
    }

    public function test_failed_ai_call_does_not_charge_credits(): void
    {
        ['admin' => $admin, 'project' => $project] = $this->seedUsers();

        Http::fake([
            'localhost:8001/analyze-feedback' => Http::response([
                'success' => false,
                'message' => 'down',
            ], 503),
        ]);

        $this->actingAs($admin)->postJson('/api/v1/ai/analyze-feedback', [
            'feedback_text' => 'Please make the homepage feel more premium overall',
            'project_type'  => 'web',
            'project_id'    => $project->id,
        ])->assertStatus(503);

        $this->assertDatabaseCount('ai_credit_usages', 0);
    }
}
