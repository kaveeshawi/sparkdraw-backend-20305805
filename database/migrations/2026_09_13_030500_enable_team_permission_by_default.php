<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Team chat / roster access should be on by default for PM + member-based roles.
        $roleIds = DB::table('custom_roles')
            ->whereIn('base_role', ['pm', 'member'])
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        DB::table('custom_role_permissions')
            ->whereIn('custom_role_id', $roleIds)
            ->where('permission_key', Permissions::TEAM)
            ->update(['allowed' => true]);

        // Ensure the key exists even if an older role never got it backfilled.
        foreach ($roleIds as $roleId) {
            $exists = DB::table('custom_role_permissions')
                ->where('custom_role_id', $roleId)
                ->where('permission_key', Permissions::TEAM)
                ->exists();

            if (!$exists) {
                DB::table('custom_role_permissions')->insert([
                    'custom_role_id' => $roleId,
                    'permission_key' => Permissions::TEAM,
                    'allowed'        => true,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Do not force Team off again — admins may have intentionally left it on.
    }
};
