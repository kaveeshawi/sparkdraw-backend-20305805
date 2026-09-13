<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomRolePermission extends Model
{
    protected $fillable = [
        'custom_role_id',
        'permission_key',
        'allowed',
    ];

    protected function casts(): array
    {
        return [
            'allowed' => 'boolean',
        ];
    }

    public function customRole(): BelongsTo
    {
        return $this->belongsTo(CustomRole::class);
    }
}
