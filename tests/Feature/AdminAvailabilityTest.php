<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function seedAgency(): array
    {
        $agency = Agency::create([
            'name'         => 'Avail Agency',
            'domain_slug'  => 'avail-agency-01',
            'brand_colors' => ['primary' => '#802AEE', 'light' => '#f3e8ff'],
        ]);

        $other = Agency::create([
            'name'         => 'Other Avail Agency',
            'domain_slug'  => 'avail-agency-02',
            'brand_colors' => ['primary' => '#2563eb', 'light' => '#dbeafe'],
        ]);

        $admin = User::create([
            'agency_id'    => $agency->id,
            'role'         => 'admin',
            'name'         => 'Admin',
            'email'        => 'admin-avail@test.com',
            'password'     => Hash::make('password'),
            'availability' => 'offline',
        ]);

        $member = User::create([
            'agency_id'    => $agency->id,
            'role'         => 'member',
            'name'         => 'Member',
            'email'        => 'member-avail@test.com',
            'password'     => Hash::make('password'),
            'availability' => 'offline',
        ]);

        $pm = User::create([
            'agency_id'    => $agency->id,
            'role'         => 'pm',
            'name'         => 'PM',
            'email'        => 'pm-avail@test.com',
            'password'     => Hash::make('password'),
            'availability' => 'available',
        ]);

        $otherAdmin = User::create([
            'agency_id'    => $other->id,
            'role'         => 'admin',
            'name'         => 'Other Admin',
            'email'        => 'other-admin-avail@test.com',
            'password'     => Hash::make('password'),
            'availability' => 'available',
        ]);

        return compact('agency', 'admin', 'member', 'pm', 'otherAdmin');
    }

    public function test_admin_can_set_member_availability_without_clock_in(): void
    {
        ['admin' => $admin, 'member' => $member] = $this->seedAgency();

        $response = $this->actingAs($admin)->patchJson("/api/v1/team/{$member->id}/availability", [
            'availability' => 'busy',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.availability', 'busy')
            ->assertJsonPath('data.is_clocked_in', false);

        $this->assertSame('busy', $member->fresh()->availability);
    }

    public function test_admin_setting_offline_closes_open_session(): void
    {
        ['admin' => $admin, 'member' => $member] = $this->seedAgency();

        WorkSession::create([
            'user_id'     => $member->id,
            'agency_id'   => $member->agency_id,
            'clock_in_at' => now()->subHour(),
        ]);
        $member->update(['availability' => 'available']);

        $response = $this->actingAs($admin)->patchJson("/api/v1/team/{$member->id}/availability", [
            'availability' => 'offline',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.availability', 'offline')
            ->assertJsonPath('data.is_clocked_in', false);

        $this->assertDatabaseMissing('work_sessions', [
            'user_id'      => $member->id,
            'clock_out_at' => null,
        ]);
    }

    public function test_pm_cannot_set_member_availability(): void
    {
        ['pm' => $pm, 'member' => $member] = $this->seedAgency();

        $this->actingAs($pm)
            ->patchJson("/api/v1/team/{$member->id}/availability", ['availability' => 'away'])
            ->assertForbidden();
    }

    public function test_admin_cannot_set_other_agency_member(): void
    {
        ['admin' => $admin, 'otherAdmin' => $otherAdmin] = $this->seedAgency();

        $this->actingAs($admin)
            ->patchJson("/api/v1/team/{$otherAdmin->id}/availability", ['availability' => 'busy'])
            ->assertNotFound();
    }
}
