<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageThreadRead extends Model
{
    use HasAgencyScope;

    protected $fillable = [
        'agency_id',
        'user_id',
        'project_id',
        'peer_user_id',
        'last_read_at',
    ];

    protected $casts = [
        'peer_user_id' => 'integer',
        'last_read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
