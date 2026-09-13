<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\UpsellSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UpsellTest extends TestCase
{
    use RefreshDatabase;

    private function seedUpsellData(): array
    {
        $agency = Agency::create(['name' => 'Upsell Agency', 'domain_slug' => 'upsell-ag', 'brand_colors' => ['primary' => '#802AEE']]);
        $admin  = User::create(['agency_id' => $agency->id, 'role' => 'admin', 'name' => 'Admin', 'email' => 'admin@upsell.test', 'password' => Hash::make('password')]);
        $pm     = User::create(['agency_id' => $agency->id, 'role' => 'pm', 'name' => 'PM', 'email' => 'pm@upsell.test', 'password' => Hash::make('password')]);
        $contact = User::create(['agency_id' => $agency->id, 'role' => 'client', 'name' => 'Client', 'email' => 'c@upsell.test', 'password' => Hash::make('password')]);
        $client = Client::create(['agency_id' => $agency->id, 'company_name' => 'Upsell Co', 'contact_user_id' => $contact->id]);
        $project = Project::create(['agency_id' => $agency->id, 'client_id' => $client->id, 'name' => 'Upsell Project', 'type' => 'web', 'status' => 'active', 'start_date' => now(), 'end_date' => now()->addMonth()]);

        $suggestion = UpsellSuggestion::create([
            'agency_id' => $agency->id, 'project_id' => $project->id,
            'service_type' => 'SEO package', 'confidence' => 0.75,
            'admin_status' => 'pending', 'client_status' => 'hidden',
        ]);

        return compact('agency', 'admin', 'pm', 'suggestion');
    }

    public function test_admin_can_approve_upsell_suggestion(): void
    {
        ['admin' => $admin, 'suggestion' => $suggestion] = $this->seedUpsellData();

        $response = $this->actingAs($admin)->patchJson("/api/v1/upsell-suggestions/{$suggestion->id}/approve");

        $response->assertOk()->assertJsonPath('data.admin_status', 'approved');
        $this->assertDatabaseHas('upsell_suggestions', ['id' => $suggestion->id, 'admin_status' => 'approved']);
    }

    public function test_approve_logs_project_event(): void
    {
        ['admin' => $admin, 'suggestion' => $suggestion] = $this->seedUpsellData();

        $this->actingAs($admin)->patchJson("/api/v1/upsell-suggestions/{$suggestion->id}/approve");

        $this->assertDatabaseHas('project_events', [
            'event_type' => 'upsell_approved',
            'project_id' => $suggestion->project_id,
        ]);
    }

    public function test_pm_cannot_approve_upsell(): void
    {
        ['pm' => $pm, 'suggestion' => $suggestion] = $this->seedUpsellData();

        $this->actingAs($pm)->patchJson("/api/v1/upsell-suggestions/{$suggestion->id}/approve")->assertStatus(403);
    }

    public function test_admin_can_send_and_undo_upsell(): void
    {
        ['admin' => $admin, 'suggestion' => $suggestion] = $this->seedUpsellData();

        $this->actingAs($admin)
            ->patchJson("/api/v1/upsell-suggestions/{$suggestion->id}/send")
            ->assertOk()
            ->assertJsonPath('data.client_status', 'shown')
            ->assertJsonPath('data.admin_status', 'approved');

        $this->assertDatabaseHas('upsell_suggestions', [
            'id' => $suggestion->id,
            'client_status' => 'shown',
            'admin_status' => 'approved',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/upsell-suggestions/{$suggestion->id}/undo")
            ->assertOk()
            ->assertJsonPath('data.client_status', 'hidden')
            ->assertJsonPath('data.admin_status', 'pending');

        $this->assertDatabaseHas('upsell_suggestions', [
            'id' => $suggestion->id,
            'client_status' => 'hidden',
            'admin_status' => 'pending',
        ]);
    }
}
