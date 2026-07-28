<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerts_endpoint_returns_correct_event_types(): void
    {
        $agency = Agency::create(['name' => 'Alert Agency', 'domain_slug' => 'alert-ag', 'brand_colors' => ['primary' => '#802AEE']]);
        $admin  = User::create(['agency_id' => $agency->id, 'role' => 'admin', 'name' => 'Admin', 'email' => 'admin@alert.test', 'password' => Hash::make('password')]);
        $contact = User::create(['agency_id' => $agency->id, 'role' => 'client', 'name' => 'C', 'email' => 'c@alert.test', 'password' => Hash::make('password')]);
        $client = Client::create(['agency_id' => $agency->id, 'company_name' => 'Alert Co', 'contact_user_id' => $contact->id]);
        $project = Project::create(['agency_id' => $agency->id, 'client_id' => $client->id, 'name' => 'Alert Project', 'type' => 'web', 'status' => 'active']);

        ProjectEvent::log($agency->id, $project->id, 'health_score_critical', ['score' => 30]);
        ProjectEvent::log($agency->id, $project->id, 'revision_risk_detected', ['round' => 3]);
        ProjectEvent::log($agency->id, null, 'user_invited', ['email' => 'x@test.com']); // not an alert type

        $response = $this->actingAs($admin)->getJson('/api/v1/project-events/alerts');

        $response->assertOk();
        $types = collect($response->json('data'))->pluck('event_type')->all();
        $this->assertContains('health_score_critical', $types);
        $this->assertContains('revision_risk_detected', $types);
        $this->assertNotContains('user_invited', $types);
    }

    public function test_alerts_scoped_to_agency(): void
    {
        $agencyA = Agency::create(['name' => 'Agency A', 'domain_slug' => 'alert-a', 'brand_colors' => ['primary' => '#802AEE']]);
        $agencyB = Agency::create(['name' => 'Agency B', 'domain_slug' => 'alert-b', 'brand_colors' => ['primary' => '#802AEE']]);
        $adminA  = User::create(['agency_id' => $agencyA->id, 'role' => 'admin', 'name' => 'Admin A', 'email' => 'a@alert.test', 'password' => Hash::make('password')]);

        ProjectEvent::log($agencyA->id, null, 'deadline_at_risk', ['task' => 'A task']);
        ProjectEvent::log($agencyB->id, null, 'deadline_at_risk', ['task' => 'B task']);

        $response = $this->actingAs($adminA)->getJson('/api/v1/project-events/alerts');
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('deadline_at_risk', $data[0]['event_type']);
    }
}
