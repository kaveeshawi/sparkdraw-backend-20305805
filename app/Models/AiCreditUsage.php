<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCreditUsage extends Model
{
    use HasAgencyScope;

    protected $fillable = [
        'agency_id',
        'user_id',
        'feature',
        'credits',
        'metadata',
    ];

    protected $casts = [
        'credits'  => 'integer',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
