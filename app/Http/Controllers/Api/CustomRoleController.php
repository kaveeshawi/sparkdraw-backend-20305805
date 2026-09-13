<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CustomRole;
use App\Models\User;
use App\Services\RoleBootstrapService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomRoleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RoleBootstrapService $roleBootstrap,
    ) {}

    // GET /api/v1/roles — admin + pm (read); mutations are admin-only (route-gated)
    public function index(Request $request): JsonResponse
    {
        $agencyId = $request->user()->agency_id;

        // Only seed the 3 defaults the first time this agency has no roles at all —
        // an admin may have deliberately deleted a default afterward, and it must stay gone.
        if (CustomRole::query()->where('agency_id', $agencyId)->doesntExist()) {
            $this->roleBootstrap->ensureForAgency($agencyId);
        } else {
            $this->roleBootstrap->backfillMissingPermissions($agencyId);
        }

        $roles = CustomRole::query()
            ->withCount('members')
            ->with('permissions')
            ->orderByRaw("CASE base_role WHEN 'admin' THEN 0 WHEN 'pm' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get();

        return $this->success($roles->map(fn (CustomRole $role) => $this->rolePayload($role)));
    }

    // POST /api/v1/roles — admin only
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                          => [
                'required', 'string', 'max:100',
                Rule::unique('custom_roles', 'name')->where(
                    fn ($q) => $q->where('agency_id', $request->user()->agency_id),
                ),
            ],
            'base_role'                     => ['required', Rule::in(['pm', 'member'])],
            'permissions'                   => ['sometimes', 'array'],
            'permissions.*'                 => ['boolean'],
        ]);

        $role = CustomRole::create([
            'agency_id'  => $request->user()->agency_id,
            'name'       => trim($validated['name']),
            'base_role'  => $validated['base_role'],
            'is_default' => false,
            'is_locked'  => false,
        ]);

        $role->syncPermissions(array_merge(
            Permissions::defaultsFor($validated['base_role']),
            $validated['permissions'] ?? [],
        ));

        return $this->created($this->rolePayload($role->fresh(['permissions'])->loadCount('members')), 'Role created.');
    }

    // PUT /api/v1/roles/{customRole} — admin only
    public function update(Request $request, CustomRole $customRole): JsonResponse
    {
        if ($customRole->is_locked) {
            return $this->error('Agency Admin permissions cannot be changed.', [], 422);
        }

        $validated = $request->validate([
            'name'           => [
                'sometimes', 'string', 'max:100',
                Rule::unique('custom_roles', 'name')
                    ->where(fn ($q) => $q->where('agency_id', $request->user()->agency_id))
                    ->ignore($customRole->id),
            ],
            'permissions'    => ['sometimes', 'array'],
            'permissions.*'  => ['boolean'],
        ]);

        if (isset($validated['name'])) {
            $customRole->update(['name' => trim($validated['name'])]);
        }

        if (isset($validated['permissions'])) {
            $customRole->syncPermissions($validated['permissions']);
        }

        return $this->success(
            $this->rolePayload($customRole->fresh(['permissions'])->loadCount('members')),
            'Role updated.',
        );
    }

    // DELETE /api/v1/roles/{customRole} — admin only
    public function destroy(CustomRole $customRole): JsonResponse
    {
        if ($customRole->is_locked) {
            return $this->error('Agency Admin cannot be removed.', [], 422);
        }

        // Members assigned to this role fall back to their base tier's default role,
        // if one still exists — otherwise they simply have no granted permissions
        // until the admin assigns them a role.
        User::query()->where('custom_role_id', $customRole->id)->update(['custom_role_id' => null]);

        $customRole->delete();

        return $this->success(message: 'Role removed.');
    }

    private function rolePayload(CustomRole $role): array
    {
        return [
            'id'          => $role->id,
            'name'        => $role->name,
            'base_role'   => $role->base_role,
            'is_default'  => $role->is_default,
            'is_locked'   => $role->is_locked,
            'member_count' => $role->members_count ?? 0,
            'permissions' => $role->permissionsMap(),
        ];
    }
}
