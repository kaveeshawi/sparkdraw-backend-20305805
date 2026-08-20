<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyIntegration extends Model
{
    use HasAgencyScope;

    protected $fillable = [
        'agency_id',
        'provider',
        'status',
        'credentials',
        'connected_by',
        'connected_at',
    ];

    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials'  => 'encrypted:array',
        'connected_at' => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
