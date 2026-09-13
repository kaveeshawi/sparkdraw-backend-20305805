<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\CustomRole;
use App\Support\Permissions;
use Illuminate\Support\Collection;

class RoleBootstrapService
{
    public const DEFAULT_NAMES = [
        'admin'  => 'Agency Admin',
        'pm'     => 'Project Manager',
        'member' => 'Team Member',
    ];

    /** @return Collection<int,CustomRole> the 3 default roles for this agency, creating any that are missing */
    public function ensureForAgency(int $agencyId): Collection
    {
        $roles = collect();

        foreach (self::DEFAULT_NAMES as $baseRole => $name) {
            $role = CustomRole::withoutAgencyScope()->firstOrCreate(
                ['agency_id' => $agencyId, 'base_role' => $baseRole, 'is_default' => true],
                [
                    'name'      => $name,
                    'is_locked' => $baseRole === 'admin',
                ],
            );

            if ($role->permissions()->count() === 0) {
                foreach (Permissions::defaultsFor($baseRole) as $key => $allowed) {
                    $role->permissions()->create([
                        'permission_key' => $key,
                        'allowed'        => $allowed,
                    ]);
                }
            } else {
                $this->backfillRolePermissions($role);
            }

            $roles->push($role);
        }

        return $roles;
    }

    /** Add newly introduced permission keys to existing roles without overwriting toggles. */
    public function backfillMissingPermissions(int $agencyId): void
    {
        $roles = CustomRole::withoutAgencyScope()
            ->where('agency_id', $agencyId)
            ->get();

        foreach ($roles as $role) {
            $this->backfillRolePermissions($role);
        }
    }

    private function backfillRolePermissions(CustomRole $role): void
    {
        foreach (Permissions::defaultsFor($role->base_role) as $key => $allowed) {
            $role->permissions()->firstOrCreate(
                ['permission_key' => $key],
                ['allowed' => $allowed],
            );
        }
    }

    public function ensureForAllAgencies(): int
    {
        $count = 0;
        foreach (Agency::query()->pluck('id') as $agencyId) {
            $this->ensureForAgency($agencyId);
            $count++;
        }

        return $count;
    }
}
