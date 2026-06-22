<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasAgencyScope, SoftDeletes;

    protected $fillable = [
        'agency_id', 'project_id', 'milestone_id', 'assignee_id',
        'title', 'description', 'status', 'priority',
        'estimated_hours', 'actual_hours', 'deadline',
    ];

    protected $casts = [
        'deadline' => 'date',
    ];

    protected $appends = ['burn_ratio'];

    // burn_ratio feeds the C3 Health Score rule: >0.85 with <50% project done = risk signal.
    public function getBurnRatioAttribute(): float
    {
        if (!$this->estimated_hours || $this->estimated_hours === 0) {
            return 0.0;
        }
        return round((float) ($this->actual_hours ?? 0) / $this->estimated_hours, 4);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function timeLogs(): HasMany
    {
        return $this->hasMany(TimeLog::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
