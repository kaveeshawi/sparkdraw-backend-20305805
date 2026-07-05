<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasAgencyScope, SoftDeletes;

    public const TIER_VIP = 'vip';

    public const TIER_ENTERPRISE = 'enterprise';

    public const TIERS = [
        self::TIER_VIP,
        self::TIER_ENTERPRISE,
    ];

    protected $fillable = ['agency_id', 'company_name', 'tier', 'contact_user_id'];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function contactUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contact_user_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeTier(Builder $query, string $tier): Builder
    {
        return $query->where('tier', $tier);
    }

    public function isVip(): bool
    {
        return $this->tier === self::TIER_VIP;
    }

    public function isEnterprise(): bool
    {
        return $this->tier === self::TIER_ENTERPRISE;
    }

    /**
     * Normalize incoming tier values before persist (empty → null, lowercase).
     */
    public static function normalizeTier(?string $tier): ?string
    {
        if ($tier === null) {
            return null;
        }

        $normalized = strtolower(trim($tier));

        if ($normalized === '' || ! in_array($normalized, self::TIERS, true)) {
            return null;
        }

        return $normalized;
    }
}
