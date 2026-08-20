<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    private function seedAgency(): array
    {
        $agency = Agency::create([
            'name'         => 'Test Agency',
            'domain_slug'  => 'test-team-mgmt',
            'brand_colors' => ['primary' => '#802AEE'],
        ]);

        $admin = User::create([
            'agency_id' => $agency->id,
            'role'      => 'admin',
            'name'      => 'Agency Admin',
            'email'     => 'admin-team@test.com',
            'password'  => Hash::make('password'),
            'job_title' => 'Agency Admin',
            'department' => null,
        ]);

        $member = User::create([
            'agency_id' => $agency->id,
            'role'      => 'member',
            'name'      => 'Alex Member',
            'email'     => 'member-team@test.com',
            'password'  => Hash::make('password'),
            'department' => 'Design',
        ]);

        return compact('agency', 'admin', 'member');
    }

    public function test_team_index_includes_admin_with_is_protected(): void
    {
        ['admin' => $admin] = $this->seedAgency();

        $response = $this->actingAs($admin)->getJson('/api/v1/team');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $rows = $response->json('data');
        $adminRow = collect($rows)->firstWhere('email', 'admin-team@test.com');

        $this->assertNotNull($adminRow);
        $this->assertTrue($adminRow['is_protected']);
        $this->assertSame('admin', $adminRow['role']);
        $this->assertSame('Management', $adminRow['department']);
        $this->assertNull($adminRow['employee_id']);
    }

    public function test_admin_cannot_be_deleted(): void
    {
        ['admin' => $admin] = $this->seedAgency();

        $this->actingAs($admin)->deleteJson("/api/v1/team/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_admin_can_be_updated_but_not_demoted(): void
    {
        ['admin' => $admin] = $this->seedAgency();

        $this->actingAs($admin)->putJson("/api/v1/team/{$admin->id}", [
            'name'       => 'Updated Admin',
            'job_title'  => 'Founder',
            'department' => 'Leadership',
            'profile_meta' => [
                'first_name' => 'Updated',
                'last_name'  => 'Admin',
            ],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Admin')
            ->assertJsonPath('data.job_title', 'Founder')
            ->assertJsonPath('data.is_protected', true);

        $this->actingAs($admin)->putJson("/api/v1/team/{$admin->id}", [
            'role' => 'member',
        ])->assertStatus(422);

        $admin->refresh();
        $this->assertSame('admin', $admin->role);
        $this->assertSame('Founder', $admin->job_title);
    }

    public function test_member_can_be_removed(): void
    {
        ['admin' => $admin, 'member' => $member] = $this->seedAgency();

        $this->actingAs($admin)->deleteJson("/api/v1/team/{$member->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
    }

    public function test_departments_crud_and_rename_syncs_members(): void
    {
        ['admin' => $admin, 'member' => $member] = $this->seedAgency();

        $create = $this->actingAs($admin)->postJson('/api/v1/departments', [
            'name' => 'Engineering',
        ])->assertCreated();

        $deptId = $create->json('data.id');

        $this->actingAs($admin)->putJson("/api/v1/team/{$member->id}", [
            'department' => 'Engineering',
        ])->assertOk();

        $this->actingAs($admin)->putJson("/api/v1/departments/{$deptId}", [
            'name' => 'Product Engineering',
        ])->assertOk();

        $member->refresh();
        $this->assertSame('Product Engineering', $member->department);

        $this->actingAs($admin)->deleteJson("/api/v1/departments/{$deptId}")
            ->assertOk();

        $member->refresh();
        $this->assertNull($member->department);

        $this->assertDatabaseMissing('departments', ['id' => $deptId]);
    }

    public function test_departments_index_ensures_management_default(): void
    {
        ['admin' => $admin] = $this->seedAgency();

        $response = $this->actingAs($admin)->getJson('/api/v1/departments');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Management'));
        $this->assertDatabaseHas('departments', [
            'agency_id' => $admin->agency_id,
            'name'      => 'Management',
        ]);
    }

    public function test_admin_can_invite_member_without_sending_email(): void
    {
        ['admin' => $admin] = $this->seedAgency();

        $response = $this->actingAs($admin)->postJson('/api/v1/team/invite', [
            'name'            => 'New Member',
            'email'           => 'new-member@test.com',
            'role'            => 'member',
            'department'      => 'Design',
            'employment_type' => 'full_time',
            'send_email'      => false,
            'employee_id'     => 'EMP-0002',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'new-member@test.com')
            ->assertJsonPath('data.employee_id', 'EMP-0002');

        $this->assertDatabaseHas('users', [
            'email' => 'new-member@test.com',
            'role'  => 'member',
        ]);
    }

    public function test_admin_avatar_can_be_uploaded(): void
    {
        ['admin' => $admin] = $this->seedAgency();

        $response = $this->actingAs($admin)->postJson("/api/v1/team/{$admin->id}/avatar", [
            'avatar' => \Illuminate\Http\UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['avatar_url', 'id']]);

        $this->assertNotNull($response->json('data.avatar_url'));
        $admin->refresh();
        $this->assertNotNull($admin->avatar_path);
    }
}
