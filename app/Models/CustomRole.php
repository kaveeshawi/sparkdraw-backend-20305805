<?php

namespace App\Models;

use App\Support\Permissions;
use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomRole extends Model
{
    use HasAgencyScope;

    protected $fillable = [
        'agency_id',
        'name',
        'base_role',
        'is_default',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_locked'  => 'boolean',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(CustomRolePermission::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'custom_role_id');
    }

    /** @return array<string,bool> */
    public function permissionsMap(): array
    {
        $stored = $this->permissions->pluck('allowed', 'permission_key')->all();
        $defaults = Permissions::defaultsFor($this->base_role);

        $map = [];
        foreach (Permissions::ALL as $key) {
            $map[$key] = array_key_exists($key, $stored)
                ? (bool) $stored[$key]
                : (bool) ($defaults[$key] ?? false);
        }

        return $map;
    }

    public function syncPermissions(array $permissions): void
    {
        foreach (Permissions::ALL as $key) {
            if (!array_key_exists($key, $permissions)) {
                continue;
            }

            $this->permissions()->updateOrCreate(
                ['permission_key' => $key],
                ['allowed' => (bool) $permissions[$key]],
            );
        }
    }
}
