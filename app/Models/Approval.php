<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class Approval extends Model
{
    use HasAgencyScope;

    protected $fillable = [
        'agency_id', 'project_id', 'deliverable_id', 'client_id',
        'requested_by_id', 'status', 'requested_at', 'responded_at',
        'approval_lag_hours',
    ];

    protected $casts = [
        'requested_at'       => 'datetime',
        'responded_at'       => 'datetime',
        'approval_lag_hours' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'deliverable_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }
}
