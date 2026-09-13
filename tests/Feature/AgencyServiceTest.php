<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AgencyService;
use App\Models\AgencyServicePackage;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AgencyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedAgency(): array
    {
        $agency = Agency::create([
            'name'         => 'Service Agency',
            'domain_slug'  => 'service-agency',
            'brand_colors' => ['primary' => '#ea580c'],
        ]);

        $admin = User::create([
            'agency_id' => $agency->id,
            'role'      => 'admin',
            'name'      => 'Admin',
            'email'     => 'admin-services@test.com',
            'password'  => Hash::make('password'),
        ]);

        return compact('agency', 'admin');
    }

    public function test_admin_can_list_and_create_services_with_packages(): void
    {
        ['admin' => $admin] = $this->seedAgency();

        $index = $this->actingAs($admin)->getJson('/api/v1/agency-services');
        $index->assertOk()->assertJsonPath('success', true);
        $this->assertGreaterThanOrEqual(1, count($index->json('data')));
        $this->assertIsArray($index->json('data.0.packages'));

        $this->actingAs($admin)->postJson('/api/v1/agency-services', [
            'name'        => 'Custom Retainer',
            'description' => 'Ongoing monthly support',
            'packages'    => [
                [
                    'name'            => 'Basic',
                    'includes'        => "Slack support\nMonthly report",
                    'duration_hours'  => 20,
                    'price'           => 800,
                    'suggested_roles' => ['Project Manager'],
                ],
                [
                    'name'            => 'Pro',
                    'includes'        => "Priority support\nWeekly sync",
                    'duration_hours'  => 40,
                    'price'           => 2000,
                    'suggested_roles' => ['Project Manager', 'Software Engineer'],
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Custom Retainer')
            ->assertJsonPath('data.packages.0.name', 'Basic')
            ->assertJsonCount(2, 'data.packages');

        $this->assertDatabaseHas('agency_services', [
            'name' => 'Custom Retainer',
        ]);
        $this->assertDatabaseHas('agency_service_packages', [
            'name'           => 'Pro',
            'duration_hours' => 40,
        ]);
    }

    public function test_admin_can_update_service_packages_and_rename_linked_projects(): void
    {
        ['agency' => $agency, 'admin' => $admin] = $this->seedAgency();

        $service = AgencyService::create([
            'agency_id'   => $agency->id,
            'name'        => 'Web Development',
            'description' => 'Sites and apps',
        ]);

        $pkg = AgencyServicePackage::create([
            'agency_id'         => $agency->id,
            'agency_service_id' => $service->id,
            'name'              => 'Starter',
            'includes'          => '5 pages',
            'price'             => 3500,
            'duration_hours'    => 80,
            'suggested_roles'   => ['Project Manager'],
        ]);

        $clientUser = User::create([
            'agency_id' => $agency->id,
            'role'      => 'client',
            'name'      => 'Client',
            'email'     => 'client-services@test.com',
            'password'  => Hash::make('password'),
        ]);

        $client = Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Acme',
            'contact_user_id' => $clientUser->id,
        ]);

        $project = Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client->id,
            'name'       => 'Acme Site',
            'type'       => 'Web Development',
            'status'     => 'active',
            'start_date' => now()->subMonth(),
            'end_date'   => now()->addMonth(),
        ]);

        $this->actingAs($admin)->putJson("/api/v1/agency-services/{$service->id}", [
            'name'        => 'Website Development',
            'description' => 'Updated description',
            'packages'    => [
                [
                    'id'              => $pkg->id,
                    'name'            => 'Pro',
                    'includes'        => "Custom app\nAPI",
                    'duration_hours'  => 120,
                    'price'           => 5000,
                    'suggested_roles' => ['Software Engineer'],
                ],
                [
                    'name'            => 'Enterprise',
                    'includes'        => 'Full team',
                    'duration_hours'  => 200,
                    'price'           => 12000,
                    'suggested_roles' => ['Project Manager', 'Software Engineer'],
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Website Development')
            ->assertJsonCount(2, 'data.packages');

        $this->assertDatabaseHas('projects', [
            'id'   => $project->id,
            'type' => 'Website Development',
        ]);
        $this->assertDatabaseHas('agency_service_packages', [
            'id'   => $pkg->id,
            'name' => 'Pro',
        ]);
        $this->assertDatabaseMissing('agency_service_packages', [
            'agency_service_id' => $service->id,
            'name'              => 'Starter',
        ]);
    }

    public function test_cannot_delete_service_in_use_by_projects(): void
    {
        ['agency' => $agency, 'admin' => $admin] = $this->seedAgency();

        $service = AgencyService::create([
            'agency_id' => $agency->id,
            'name'      => 'Branding',
        ]);

        $clientUser = User::create([
            'agency_id' => $agency->id,
            'role'      => 'client',
            'name'      => 'Client',
            'email'     => 'client2-services@test.com',
            'password'  => Hash::make('password'),
        ]);

        $client = Client::create([
            'agency_id'       => $agency->id,
            'company_name'    => 'Brand Co',
            'contact_user_id' => $clientUser->id,
        ]);

        Project::create([
            'agency_id'  => $agency->id,
            'client_id'  => $client->id,
            'name'       => 'Brand Project',
            'type'       => 'Branding',
            'status'     => 'active',
            'start_date' => now()->subMonth(),
            'end_date'   => now()->addMonth(),
        ]);

        $this->actingAs($admin)->deleteJson("/api/v1/agency-services/{$service->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('agency_services', ['id' => $service->id]);
    }
}
