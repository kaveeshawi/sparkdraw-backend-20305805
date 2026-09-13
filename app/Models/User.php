<?php

namespace App\Models;

use App\Support\Permissions;
use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, HasAgencyScope, Notifiable;

    protected $fillable = [
        'agency_id',
        'role',
        'custom_role_id',
        'name',
        'email',
        'password',
        'department',
        'employment_type',
        'availability',
        'phone',
        'job_title',
        'avatar_path',
        'profile_meta',
        'access_revoked_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'profile_meta'      => 'array',
            'access_revoked_at' => 'datetime',
        ];
    }

    public function isAccessRevoked(): bool
    {
        return $this->access_revoked_at !== null;
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function timeLogs(): HasMany
    {
        return $this->hasMany(TimeLog::class);
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function uploadedAssets(): HasMany
    {
        return $this->hasMany(Asset::class, 'uploader_id');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function clientProfile(): HasMany
    {
        return $this->hasMany(Client::class, 'contact_user_id');
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class);
    }

    public function passwordSetupTokens(): HasMany
    {
        return $this->hasMany(PasswordSetupToken::class);
    }

    public function customRole(): BelongsTo
    {
        return $this->belongsTo(CustomRole::class, 'custom_role_id');
    }

    /**
     * Granular permission check for the configurable roles system (App\Support\Permissions).
     * Admin always has every permission; client access is governed separately by the
     * client-portal routes and is unaffected by this system.
     * Missing permission rows fall back to base-role defaults so new keys stay safe.
     */
    public function hasPermission(string $key): bool
    {
        if ($this->role === 'admin') {
            return true;
        }

        if ($this->role === 'client') {
            return false;
        }

        $role = $this->customRole
            ?? CustomRole::withoutAgencyScope()
                ->where('agency_id', $this->agency_id)
                ->where('base_role', $this->role)
                ->where('is_default', true)
                ->with('permissions')
                ->first();

        $defaults = Permissions::defaultsFor($role?->base_role ?? $this->role);

        if (!$role) {
            return (bool) ($defaults[$key] ?? false);
        }

        if ($role->relationLoaded('permissions')) {
            $row = $role->permissions->firstWhere('permission_key', $key);
            if ($row !== null) {
                return (bool) $row->allowed;
            }
        } else {
            $allowed = $role->permissions()->where('permission_key', $key)->value('allowed');
            if ($allowed !== null) {
                return (bool) $allowed;
            }
        }

        return (bool) ($defaults[$key] ?? false);
    }

    /** @return array<string,bool> */
    public function permissionsMap(): array
    {
        if ($this->role === 'admin') {
            return array_fill_keys(Permissions::ALL, true);
        }

        if ($this->role === 'client') {
            return array_fill_keys(Permissions::ALL, false);
        }

        $role = $this->customRole
            ?? CustomRole::withoutAgencyScope()
                ->where('agency_id', $this->agency_id)
                ->where('base_role', $this->role)
                ->where('is_default', true)
                ->with('permissions')
                ->first();

        if ($role) {
            return $role->permissionsMap();
        }

        return Permissions::defaultsFor($this->role);
    }
}
