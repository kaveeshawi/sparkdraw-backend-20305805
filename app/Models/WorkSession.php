<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkSession extends Model
{
    use HasAgencyScope;

    protected $fillable = [
        'user_id',
        'agency_id',
        'clock_in_at',
        'clock_out_at',
    ];

    protected $casts = [
        'clock_in_at'  => 'datetime',
        'clock_out_at' => 'datetime',
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
